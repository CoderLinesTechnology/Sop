<?php

namespace App\Filament\Support\Operations\Http;

use App\Domain\Files\FileVault;
use App\Filament\Support\Operations\CsvExport;
use App\Models\AdminUser;
use App\Models\DocumentVersion;
use App\Models\EmailMessage;
use App\Models\Feedback;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\UploadedFile;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Authenticated admin endpoints that the Filament pages link to: decrypted
 * customer files and generated documents (streamed from the private vault,
 * never exposed through public URLs), sandboxed email previews and CSV
 * exports. Every endpoint checks a policy ability and every access to
 * customer content is audited.
 */
class SupportController extends Controller
{
    public function __construct(private readonly FileVault $vault) {}

    /** Download a customer upload (decrypted in memory). */
    public function file(Request $request, Order $order, UploadedFile $file): StreamedResponse
    {
        $this->authorizeFor($request, 'viewCustomerData', $order);

        $contents = $this->read($file->path, $file->disk, $file->is_encrypted);

        Audit::log('order.file_downloaded', $order, meta: [
            'file' => $file->uuid,
            'name' => $file->original_name,
            'field' => $file->field_key,
        ], admin: $this->admin($request));

        return $this->attachment($contents, $file->original_name ?: 'upload.'.$file->extension, $file->mime_type ?: 'application/octet-stream');
    }

    /** View a document version's PDF inline, or download its PDF / DOCX. */
    public function document(Request $request, Order $order, DocumentVersion $documentVersion, string $format): Response|StreamedResponse
    {
        $this->authorizeFor($request, 'viewDocuments', $order);

        $path = $format === 'pdf' ? $documentVersion->pdf_path : $documentVersion->docx_path;
        abort_if(blank($path), 404, 'This version has no '.strtoupper($format).' file.');

        $contents = $this->read($path, $documentVersion->files_disk, (bool) $documentVersion->files_encrypted);
        $inline = $format === 'pdf' && $request->boolean('inline');

        Audit::log($inline ? 'order.document_previewed' : 'order.document_downloaded', $order, meta: [
            'document_version' => $documentVersion->uuid,
            'version' => $documentVersion->version_number,
            'format' => $format,
        ], admin: $this->admin($request));

        $filename = ($format === 'pdf' ? $documentVersion->pdf_filename : $documentVersion->docx_filename)
            ?: $order->reference.'_v'.$documentVersion->version_number.'.'.$format;
        $mime = $format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        if (! $inline) {
            return $this->attachment($contents, $filename, $mime);
        }

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $this->safeName($filename), $this->asciiName($filename)),
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            // The browser's PDF viewer needs object-src; everything else stays locked down.
            'Content-Security-Policy' => "default-src 'none'; object-src 'self'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'",
        ]);
    }

    /**
     * The rendered HTML of an order email, served in a sandboxed document
     * (no scripts, no forms, no same-origin access) so its content can never
     * act inside the admin panel.
     */
    public function emailPreview(Request $request, EmailMessage $email): Response
    {
        $order = $email->order;
        abort_unless($order instanceof Order, 404);
        $this->authorizeFor($request, 'viewEmails', $order);

        Audit::log('order.email_viewed', $order, meta: [
            'email' => $email->uuid,
            'template' => $email->template_key,
        ], admin: $this->admin($request));

        $html = $email->html_body ?: '<pre>'.e((string) $email->text_body).'</pre>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; font-src data: https:; form-action 'none'; frame-ancestors 'self'",
        ]);
    }

    public function newsletterExport(Request $request): StreamedResponse
    {
        $admin = $this->admin($request);
        Gate::forUser($admin)->authorize('export', NewsletterSubscriber::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'confirmed', 'unsubscribed'])],
        ]);

        $query = NewsletterSubscriber::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        Audit::log('newsletter.exported', 'NewsletterSubscriber', meta: [
            'filters' => array_filter($filters),
            'rows' => (clone $query)->count(),
        ], admin: $admin);

        return CsvExport::download(
            'newsletter-subscribers-'.now()->format('Y-m-d-His').'.csv',
            ['Email', 'Status', 'Source', 'Consented at', 'Confirmed at', 'Unsubscribed at', 'Subscribed at'],
            fn () => $query->lazyById(1000)->map(fn (NewsletterSubscriber $s) => [
                $s->email, $s->status, $s->source, $s->consented_at, $s->confirmed_at, $s->unsubscribed_at, $s->created_at,
            ]),
        );
    }

    public function feedbackExport(Request $request): StreamedResponse
    {
        $admin = $this->admin($request);
        Gate::forUser($admin)->authorize('export', Feedback::class);

        $filters = $request->validate([
            'service' => ['nullable', 'integer'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
        ]);

        $query = Feedback::query()
            ->with(['order:id,reference', 'service:id,name', 'workflow:id,name'])
            ->when($filters['service'] ?? null, fn ($q, $service) => $q->where('service_id', $service))
            ->when($filters['rating'] ?? null, fn ($q, $rating) => $q->where('rating', $rating));

        Audit::log('feedback.exported', 'Feedback', meta: [
            'filters' => array_filter($filters),
            'rows' => (clone $query)->count(),
        ], admin: $admin);

        return CsvExport::download(
            'feedback-'.now()->format('Y-m-d-His').'.csv',
            ['Submitted at', 'Order', 'Service', 'Rating', 'What they liked', 'What to improve', 'AI workflow', 'Prompt versions'],
            fn () => $query->lazyById(500)->map(fn (Feedback $f) => [
                $f->created_at, $f->order?->reference, $f->service?->name, $f->rating, $f->liked, $f->improve,
                $f->workflow?->name, $f->prompt_versions,
            ]),
        );
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    private function authorizeFor(Request $request, string $ability, Order $order): void
    {
        $admin = $this->admin($request);

        abort_unless(
            Gate::forUser($admin)->allows('view', $order) && Gate::forUser($admin)->allows($ability, $order),
            403,
        );
    }

    private function read(string $path, ?string $disk, bool $encrypted): string
    {
        try {
            return $this->vault->get($path, $disk, $encrypted);
        } catch (Throwable $e) {
            report($e);
            abort(404, 'The file is no longer available in private storage.');
        }
    }

    private function attachment(string $contents, string $filename, string $mime): StreamedResponse
    {
        $response = response()->streamDownload(function () use ($contents) {
            echo $contents;
        }, null, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $this->safeName($filename),
            $this->asciiName($filename),
        ));

        return $response;
    }

    /** Strips path separators, quotes and control characters from a customer-supplied file name. */
    private function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '_', $name));

        return $name !== '' ? Str::limit($name, 150, '') : 'download';
    }

    private function asciiName(string $name): string
    {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($this->safeName($name))), '_');

        return $ascii !== '' ? $ascii : 'download';
    }
}
