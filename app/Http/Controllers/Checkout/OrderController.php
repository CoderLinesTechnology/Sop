<?php

namespace App\Http\Controllers\Checkout;

use App\Domain\Files\FileVault;
use App\Domain\Orders\InformationRequestService;
use App\Domain\Orders\OrderProgress;
use App\Domain\Orders\RevisionService;
use App\Domain\Payments\CheckoutException;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\AiJob;
use App\Models\DocumentVersion;
use App\Models\Feedback;
use App\Models\Order;
use App\Support\Seo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's secure order page (no account needed). Access is granted by
 * the AuthorizeOrderAccess middleware via a signed link, a session grant or a
 * verified account. Shows real progress, follow-up questions, the finished
 * documents, revisions and feedback.
 */
class OrderController extends Controller
{
    public function show(Request $request, OrderProgress $progress): View|RedirectResponse
    {
        $order = $this->order($request);

        // Opened from a signed email link: drop the signature from the address bar.
        if ($request->hasValidSignature()) {
            return redirect()->route('orders.show', $order->public_id);
        }

        $order->load(['service', 'files', 'revisions', 'feedback', 'openInformationRequest']);
        $version = $this->deliverableVersion($order);

        return view('site.orders.show', [
            'order' => $order,
            'steps' => $progress->steps($order),
            'informationRequest' => $order->openInformationRequest,
            'version' => $version,
            'revisionEligibility' => app(RevisionService::class)->eligibility($order),
            'openRevision' => $order->revisions->first(fn ($r) => $r->status->isOpen()),
            'seo' => Seo::make('Your order '.$order->reference, index: false),
        ]);
    }

    public function progress(Request $request, OrderProgress $progress): JsonResponse
    {
        $order = $this->order($request);

        return response()->json([
            'status' => $order->status->value,
            'label' => $order->status->customerLabel(),
            'delivered' => $order->status === OrderStatus::Delivered,
            'needs_information' => $order->status === OrderStatus::NeedsInformation,
            'steps' => $progress->steps($order),
        ]);
    }

    public function download(Request $request, FileVault $vault, mixed $order, string $version, string $format): Response
    {
        $model = $this->order($request);

        /** @var DocumentVersion|null $documentVersion */
        $documentVersion = $model->documentVersions()->where('uuid', $version)->first();
        abort_if(! $documentVersion || ! $documentVersion->isDeliverable(), 404);

        $path = $format === 'pdf' ? $documentVersion->pdf_path : $documentVersion->docx_path;
        $filename = $format === 'pdf' ? $documentVersion->pdf_filename : $documentVersion->docx_filename;
        $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $inline = $format === 'pdf' && $request->boolean('inline');

        $contents = $vault->get($path, $documentVersion->files_disk, $documentVersion->files_encrypted);

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes((string) $filename, '"\\').'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'",
        ]);
    }

    public function answerInformation(Request $request, InformationRequestService $service): RedirectResponse
    {
        $order = $this->order($request);
        $informationRequest = $order->openInformationRequest;
        abort_if($informationRequest === null, 404);

        $request->validate(['answers' => ['required', 'array'], 'answers.*' => ['nullable', 'string', 'max:3000']]);

        try {
            $service->answer($informationRequest, (array) $request->input('answers'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['answers' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order->public_id)->with('status', "Thank you — we've received your answer and continued working on your document.");
    }

    public function requestRevision(Request $request, RevisionService $revisions): RedirectResponse
    {
        $order = $this->order($request);
        $data = $request->validate(['request_text' => ['required', 'string', 'min:15', 'max:5000']], [
            'request_text.min' => 'Please describe what you would like us to change (at least a sentence).',
        ]);

        try {
            $result = $revisions->request($order, $data['request_text']);
        } catch (CheckoutException $e) {
            return back()->withErrors(['request_text' => $e->getMessage()])->withInput();
        }

        if ($result['redirect_url']) {
            return redirect()->away($result['redirect_url'], 303);
        }

        return redirect()->route('orders.show', $order->public_id)->with('status', "We've received your revision request. We'll email you as soon as your updated document is ready.");
    }

    public function feedback(Request $request): RedirectResponse
    {
        $order = $this->order($request);
        abort_unless($order->status === OrderStatus::Delivered || $order->delivered_at !== null, 403);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'liked' => ['nullable', 'string', 'max:2000'],
            'improve' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var AiJob|null $job */
        $job = $order->aiJobs()->where('kind', AiJob::KIND_ORDER)->latest('id')->first();

        Feedback::query()->updateOrCreate(['order_id' => $order->id], [
            'service_id' => $order->service_id,
            'ai_job_id' => $job?->id,
            'ai_workflow_id' => $job?->ai_workflow_id,
            'prompt_versions' => $job?->prompt_versions,
            'rating' => (int) $data['rating'],
            'liked' => $data['liked'] ?? null,
            'improve' => $data['improve'] ?? null,
        ]);

        return redirect()->route('orders.show', $order->public_id)->with('status', 'Thank you for your feedback — it helps us improve.');
    }

    private function order(Request $request): Order
    {
        $order = $request->route('order');
        abort_unless($order instanceof Order, 404);

        return $order;
    }

    private function deliverableVersion(Order $order): ?DocumentVersion
    {
        return $order->documentVersions()
            ->whereNotNull('pdf_path')
            ->where('qa_status', 'passed')
            ->whereIn('status', ['final', 'draft'])
            ->when($order->status !== OrderStatus::Delivered, fn ($q) => $q->where('status', 'final'))
            ->first();
    }
}
