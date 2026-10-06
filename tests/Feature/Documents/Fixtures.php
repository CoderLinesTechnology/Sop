<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\DocumentFactory;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Documents\Qa\TextNormalizer;
use App\Domain\Documents\RequirementResolver;
use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\TemplateResolver;
use App\Domain\Files\FileVault;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\Service;
use Database\Seeders\DocumentConfigurationSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use ZipArchive;

/** Shared builders and readers for the document engine tests. */
final class Fixtures
{
    public const UCAS_QUESTIONS = [
        'Why do you want to study this course or subject?',
        'How have your qualifications and studies helped you to prepare for this course or subject?',
        'What else have you done to prepare outside of education, and why are these experiences useful?',
    ];

    private const SENTENCES = [
        'My interest in distributed systems began during an internship at a payments start-up in Accra, where a single outage froze thousands of small traders’ accounts for an afternoon.',
        'I spent the following months rebuilding the reconciliation service, learning to reason about failure as carefully as about features.',
        'That experience led me to my final-year project on consensus protocols, which compared Raft and Viewstamped Replication under unreliable mobile networks.',
        'The results were modest, but they taught me how much of good engineering is careful measurement rather than clever design.',
        'At university I chose modules in algorithms, operating systems and network security, and I tutored first-year students in discrete mathematics.',
        'Explaining the same idea five different ways made me notice the gaps in my own understanding.',
        'The programme’s emphasis on systems research, and its group project with industry partners, matches the way I learn best.',
        'After graduating, I hope to work on infrastructure that keeps essential services available in regions where connectivity is intermittent.',
        'In the longer term I would like to return to research, possibly through a doctorate, and contribute open tools that other engineers can rely on.',
        'Outside class, I organise a weekly coding club for secondary-school students, which has grown from six members to more than forty.',
    ];

    public static function paragraph(int $sentences = 4, int $offset = 0): string
    {
        $text = [];
        for ($i = 0; $i < $sentences; $i++) {
            $text[] = self::SENTENCES[($offset + $i) % count(self::SENTENCES)];
        }

        return implode(' ', $text);
    }

    /** A realistic statement: optional headings with paragraphs. */
    public static function model(array $overrides = [], int $paragraphs = 4, bool $headings = true): DocumentModel
    {
        $blocks = [];
        for ($i = 0; $i < $paragraphs; $i++) {
            if ($headings && $i % 2 === 0) {
                $blocks[] = ['type' => 'heading', 'text' => ['Academic preparation', 'Why this programme', 'Experience beyond the classroom', 'Future goals'][intdiv($i, 2) % 4]];
            }
            $blocks[] = ['type' => 'paragraph', 'text' => self::paragraph(4, $i * 3)];
        }

        return DocumentModel::fromArray(array_merge([
            'title' => 'Statement of Purpose',
            'subtitle' => null,
            'applicant_name' => 'Daniel Essel',
            'language_variant' => 'en-GB',
            'blocks' => $blocks,
        ], $overrides));
    }

    public static function letter(array $overrides = []): DocumentModel
    {
        return DocumentModel::fromArray(array_merge([
            'title' => 'Motivation Letter — MSc Data Science',
            'applicant_name' => 'Daniel Essel',
            'language_variant' => 'en-GB',
            'blocks' => [
                ['type' => 'salutation', 'text' => 'Dear Admissions Committee,'],
                ['type' => 'paragraph', 'text' => self::paragraph(3)],
                ['type' => 'paragraph', 'text' => self::paragraph(3, 3)],
                ['type' => 'paragraph', 'text' => self::paragraph(2, 7)],
                ['type' => 'closing', 'text' => 'Yours sincerely,'],
                ['type' => 'signature', 'text' => 'Daniel Essel'],
            ],
        ], $overrides));
    }

    public static function order(array $attributes = [], array $service = []): Order
    {
        $snapshot = $attributes['service_snapshot'] ?? [];
        unset($attributes['service_snapshot']);
        $model = Service::factory()->create($service + ['document_kind' => $snapshot['document_kind'] ?? 'statement_of_purpose']);

        return Order::factory()->for($model)->create(array_merge([
            'customer_name' => 'Daniel Essel',
            'applicant_name' => 'Daniel Essel',
            'institution' => 'University of Edinburgh',
            'programme' => 'MSc Computer Science',
            'degree_level' => 'masters',
            'country_code' => 'GB',
            'word_limit' => null,
            'essay_prompt' => null,
            'service_snapshot' => array_merge([
                'name' => 'Statement of Purpose',
                'document_kind' => 'statement_of_purpose',
                'default_word_limit' => 900,
                'revision_window_days' => 14,
            ], $snapshot),
        ], $attributes));
    }

    /** Resolve requirements for a fresh order built from the given attributes. */
    public static function resolve(array $order = [], array $researched = [], array $customer = [], array $service = []): ResolvedRequirements
    {
        return app(RequirementResolver::class)->resolve(self::order($order, $service), $researched, $customer);
    }

    /** A research finding in the shape the research stage produces. */
    public static function finding(string $field, mixed $value, string $type = 'official_programme', bool $verified = true, string $url = 'https://www.ed.ac.uk/informatics/postgraduate/apply'): array
    {
        return ['field' => $field, 'value' => $value, 'source_url' => $url, 'source_type' => $type, 'quote' => 'quoted text', 'verified' => $verified];
    }

    public static function template(string $slug, array $attributes = []): DocumentTemplate
    {
        return DocumentTemplate::query()->create(array_merge(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true], $attributes));
    }

    public static function motivationOrder(array $attributes = []): Order
    {
        return self::order($attributes + [
            'country_code' => 'DE',
            'institution' => 'Technical University of Munich',
            'programme' => 'MSc Data Science',
            'service_snapshot' => ['name' => 'Motivation Letter', 'document_kind' => 'motivation_letter', 'default_word_limit' => 600],
        ]);
    }

    /** An answer of roughly the given number of characters, built from whole sentences. */
    public static function ucasAnswer(int $characters, int $offset = 0): string
    {
        $text = '';
        for ($i = $offset; mb_strlen($text) < $characters - 40; $i++) {
            $text = trim($text.' '.self::paragraph(1, $i));
        }

        return $text;
    }

    /** @param list<int> $answerLengths one entry per UCAS question answered */
    public static function ucasStatement(array $answerLengths): DocumentModel
    {
        $blocks = [];
        foreach (self::UCAS_QUESTIONS as $i => $question) {
            if (isset($answerLengths[$i])) {
                $blocks[] = ['type' => 'heading', 'text' => $question];
                $blocks[] = ['type' => 'paragraph', 'text' => self::ucasAnswer($answerLengths[$i], $i * 3)];
            }
        }

        return DocumentModel::fromArray(['title' => 'Personal Statement', 'applicant_name' => 'Amara Okafor', 'blocks' => $blocks]);
    }

    public static function ucasOrder(): Order
    {
        return self::order([
            'applicant_name' => 'Amara Okafor',
            'institution' => 'University of Leeds',
            'programme' => 'Computer Science BSc',
            'degree_level' => 'undergraduate',
            'service_snapshot' => ['name' => 'Personal Statement', 'document_kind' => 'personal_statement', 'default_word_limit' => 650],
        ]);
    }

    /**
     * A private disk for this test process only. Other suites running at the
     * same time in this checkout fake (and wipe) the shared "private" root.
     */
    public static function isolatePrivateDisk(): void
    {
        $root = storage_path('framework/testing/disks/private-documents-'.getmypid());
        (new Filesystem)->ensureDirectoryExists($root);
        (new Filesystem)->cleanDirectory($root);
        Storage::set('private', Storage::createLocalDriver(['root' => $root, 'throw' => true]));
    }

    public static function removePrivateDisk(): void
    {
        (new Filesystem)->deleteDirectory(storage_path('framework/testing/disks/private-documents-'.getmypid()));
    }

    public static function seed(): void
    {
        (new DocumentConfigurationSeeder)->run();
    }

    /** Resolve requirements + template for the order, create the version and render it. */
    public static function rendered(Order $order, ?DocumentModel $model = null, ?ResolvedRequirements $requirements = null, ?DocumentTemplate $template = null): DocumentVersion
    {
        $requirements ??= app(RequirementResolver::class)->resolve($order);
        $template ??= app(TemplateResolver::class)->resolve($order, $requirements);
        $version = app(DocumentFactory::class)->createVersion($order, $model ?? self::model(), $template, $requirements);

        return app(DocumentRenderer::class)->render($version);
    }

    public static function pdfBytes(DocumentVersion $version): string
    {
        return app(FileVault::class)->get($version->pdf_path, $version->files_disk, $version->files_encrypted);
    }

    public static function docxBytes(DocumentVersion $version): string
    {
        return app(FileVault::class)->get($version->docx_path, $version->files_disk, $version->files_encrypted);
    }

    /** pdftotext output (reading order), pages separated by form feeds. */
    public static function pdfText(string $pdf, array $options = []): string
    {
        $path = self::temp($pdf, 'pdf');
        try {
            $process = new Process(['/usr/bin/pdftotext', ...$options, '-enc', 'UTF-8', $path, '-']);
            $process->mustRun();

            return $process->getOutput();
        } finally {
            @unlink($path);
        }
    }

    public static function pdfInfo(string $pdf): string
    {
        $path = self::temp($pdf, 'pdf');
        try {
            return (new Process(['/usr/bin/pdfinfo', $path]))->mustRun()->getOutput();
        } finally {
            @unlink($path);
        }
    }

    public static function pdfFonts(string $pdf): string
    {
        $path = self::temp($pdf, 'pdf');
        try {
            return (new Process(['/usr/bin/pdffonts', $path]))->mustRun()->getOutput();
        } finally {
            @unlink($path);
        }
    }

    /** A file inside the DOCX package. */
    public static function docxPart(string $docx, string $part): string
    {
        $path = self::temp($docx, 'docx');
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = (string) $zip->getFromName($part);
            $zip->close();

            return $xml;
        } finally {
            @unlink($path);
        }
    }

    /** Rewrite one part of a DOCX package (to simulate a damaged or edited file). */
    public static function withDocxPart(string $docx, string $part, callable $change): string
    {
        $path = self::temp($docx, 'docx');
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $zip->addFromString($part, $change((string) $zip->getFromName($part)));
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /** Whitespace-free text, for comparing extracted text with model text. */
    public static function compact(string $text): string
    {
        return TextNormalizer::compact($text);
    }

    /** Swap a stored file of a version for other bytes (re-encrypted in the vault). */
    public static function replaceStored(DocumentVersion $version, string $format, string $bytes, bool $updateChecksum = true): void
    {
        $stored = app(FileVault::class)->put('documents', $bytes, $format);
        $version->forceFill(array_filter([
            $format.'_path' => $stored['path'],
            $format.'_size' => $stored['size'],
            $format.'_sha256' => $updateChecksum ? $stored['sha256'] : null,
        ]))->save();
    }

    private static function temp(string $bytes, string $extension): string
    {
        $path = sys_get_temp_dir().'/doc-test-'.bin2hex(random_bytes(8)).'.'.$extension;
        file_put_contents($path, $bytes);

        return $path;
    }
}
