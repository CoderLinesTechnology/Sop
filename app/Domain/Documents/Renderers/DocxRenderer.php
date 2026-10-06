<?php

namespace App\Domain\Documents\Renderers;

use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\TemplateSnapshot;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style\Language;
use RuntimeException;
use ZipArchive;

/**
 * Renders a DocumentLayout to an editable Word document: real text in
 * paragraphs (no images, text boxes or protection), standard font names,
 * built-in Title / Heading 1 styles, the same spacing as the PDF, header and
 * footer with live PAGE / NUMPAGES fields, and the proofing language set to
 * the document's English variant.
 */
final class DocxRenderer
{
    private const TWIPS_PER_MM = 1440 / 25.4;

    private const NEUTRAL_APP_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
        .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
        .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><DocSecurity>0</DocSecurity></Properties>';

    public function render(DocumentLayout $layout): string
    {
        Settings::setOutputEscapingEnabled(true);

        $s = $layout->snapshot;
        $family = $s['font_family'];
        $word = new PhpWord;
        $word->getCompatibility()->setOoxmlVersion(15); // modern Word: no "Compatibility Mode"
        $word->getSettings()->setThemeFontLang(new Language($layout->languageVariant)); // proofing language
        $word->setDefaultFontName($family);
        $word->setDefaultAsianFontName($family);
        $word->setDefaultFontSize((float) $s['font_size']);

        $author = (string) $layout->visibleApplicantName();
        $word->getDocInfo()
            ->setTitle($layout->title)
            ->setCreator($author)
            ->setLastModifiedBy($author)
            ->setCompany('')
            ->setManager('')
            ->setDescription('')
            ->setSubject('')
            ->setKeywords('')
            ->setCategory('');

        $this->defineStyles($word, $layout);

        [$width, $height] = TemplateSnapshot::pageDimensions($s);
        $section = $word->addSection([
            'orientation' => 'portrait',
            'pageSizeW' => $this->twips($width),
            'pageSizeH' => $this->twips($height),
            'marginTop' => $this->twips($s['margin_top_mm']),
            'marginRight' => $this->twips($s['margin_right_mm']),
            'marginBottom' => $this->twips($s['margin_bottom_mm']),
            'marginLeft' => $this->twips($s['margin_left_mm']),
            'headerHeight' => $this->twips($layout->marginalDistance('header')),
            'footerHeight' => $this->twips($layout->marginalDistance('footer')),
        ]);

        foreach ($layout->items as $index => $item) {
            $f = $layout->format($index);
            $run = $section->addTextRun(array_filter([
                'styleName' => match ($item['role']) {
                    'title' => 'Title',
                    'subtitle' => 'Subtitle',
                    'heading' => 'Heading1',
                    default => null,
                },
                'alignment' => $this->alignment($f['align']),
                'spaceBefore' => (int) round($f['before'] * 20),
                'spaceAfter' => (int) round($f['after'] * 20),
                // PHPWord adds the 240 twips of a single line to "auto" spacing itself.
                'spacing' => (int) round(($f['line'] - 1) * 240),
                'spacingLineRule' => 'auto',
                'indentation' => ['firstLine' => (int) round($f['indent_mm'] * self::TWIPS_PER_MM)],
                'keepNext' => $f['keep_next'],
                'keepLines' => $item['role'] === 'heading',
            ], fn ($value) => $value !== null));

            $this->addText($run, $item['text'], [
                'size' => $f['size'],
                'bold' => $f['bold'],
                'italic' => $f['italic'],
            ]);
        }

        if ($layout->header !== []) {
            $this->marginal($section->addHeader(), $layout->header, 'Header');
        }
        if ($layout->footer !== []) {
            $this->marginal($section->addFooter(), $layout->footer, 'Footer');
        }

        return $this->save($word);
    }

    private function defineStyles(PhpWord $word, DocumentLayout $layout): void
    {
        $s = $layout->snapshot;
        $base = ['name' => $s['font_family'], 'color' => '000000'];

        $word->addTitleStyle(0, $base + ['size' => (float) $s['title_font_size'], 'bold' => true], ['alignment' => $this->alignment($s['title_align']), 'keepNext' => true]);
        $word->addTitleStyle(1, $base + ['size' => (float) $s['heading_font_size'], 'bold' => true], ['keepNext' => true, 'keepLines' => true]);
        $word->addFontStyle('Subtitle', $base + ['size' => (float) $s['font_size'], 'italic' => true], ['alignment' => $this->alignment($s['title_align']), 'keepNext' => true]);

        $marginal = $base + ['size' => $layout->marginalFontSize()];
        $word->addFontStyle('Header', $marginal, ['spaceBefore' => 0, 'spaceAfter' => 0]);
        $word->addFontStyle('Footer', $marginal, ['spaceBefore' => 0, 'spaceAfter' => 0]);
    }

    /** @param list<array{text:string,align:string,page_number:bool}> $lines */
    private function marginal(AbstractContainer $container, array $lines, string $style): void
    {
        foreach ($lines as $line) {
            $run = $container->addTextRun(['styleName' => $style, 'alignment' => $this->alignment($line['align']), 'spaceAfter' => 0]);
            if (! $line['page_number']) {
                $this->addText($run, $line['text'], []);

                continue;
            }

            // "Page {PAGE} of {NUMPAGES}" → text runs and live fields.
            foreach (preg_split('/(\{PAGE\}|\{NUMPAGES\})/', $line['text'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                match ($part) {
                    '{PAGE}' => $run->addField('PAGE', ['format' => 'Arabic']),
                    '{NUMPAGES}' => $run->addField('NUMPAGES', ['format' => 'Arabic']),
                    default => $run->addText($part),
                };
            }
        }
    }

    /** Text with soft line breaks (signature blocks, addresses) inside one paragraph. */
    private function addText(TextRun $run, string $text, array $font): void
    {
        foreach (explode("\n", $text) as $i => $line) {
            if ($i > 0) {
                $run->addTextBreak();
            }
            if ($line !== '') {
                $run->addText($line, $font ?: null);
            }
        }
    }

    /** Transitional OOXML values, understood by every Word version and LibreOffice. */
    private function alignment(string $align): string
    {
        return match ($align) {
            'justify' => 'both',
            'center' => 'center',
            'right' => 'right',
            default => 'left',
        };
    }

    private function twips(float|int|string $mm): int
    {
        return (int) round((float) $mm * self::TWIPS_PER_MM);
    }

    private function save(PhpWord $word): string
    {
        $directory = storage_path('app/tmp');
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.bin2hex(random_bytes(12)).'.docx';

        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);

            // Neutral package metadata: no generator name in docProps/app.xml.
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Generated DOCX could not be reopened.');
            }
            $zip->addFromString('docProps/app.xml', self::NEUTRAL_APP_XML);
            $zip->close();

            $bytes = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if ($bytes === '') {
            throw new RuntimeException('DOCX rendering produced an empty file.');
        }

        return $bytes;
    }
}
