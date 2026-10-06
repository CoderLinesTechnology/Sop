<?php

namespace App\Domain\Documents\Qa;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

/**
 * Reads Word documents safely (zip-bomb, path and DTD checks) and exposes
 * what file QA and DOCX import need: body paragraphs with their styles,
 * fonts, page fields, proofing language and anything that would stop the
 * text being freely editable.
 */
class DocxInspector
{
    public const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const MAX_ENTRIES = 2000;

    private const MAX_UNCOMPRESSED = 60 * 1024 * 1024;

    /**
     * @return array{ok:bool, error:?string, entries:list<string>, parts:array<string,string>}
     */
    public function open(string $bytes): array
    {
        $result = ['ok' => false, 'error' => null, 'entries' => [], 'parts' => []];
        if (! str_starts_with($bytes, "PK\x03\x04")) {
            return ['error' => 'The file is not a zip package.'] + $result;
        }

        $temp = $this->tempFile($bytes);
        $zip = new ZipArchive;

        try {
            if ($zip->open($temp, ZipArchive::RDONLY) !== true) {
                return ['error' => 'The DOCX package could not be opened.'] + $result;
            }
            if ($zip->numFiles > self::MAX_ENTRIES) {
                return ['error' => 'The DOCX package has too many entries.'] + $result;
            }

            $entries = [];
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = (array) $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                $size += (int) ($stat['size'] ?? 0);
                if (str_contains($name, '..') || str_starts_with($name, '/')) {
                    return ['error' => 'The DOCX package contains an unsafe path.'] + $result;
                }
                if ($size > self::MAX_UNCOMPRESSED) {
                    return ['error' => 'The DOCX package is too large when uncompressed.'] + $result;
                }
                $entries[] = $name;
            }

            foreach (['[Content_Types].xml', 'word/document.xml'] as $required) {
                if (! in_array($required, $entries, true)) {
                    return ['error' => "The DOCX package has no {$required}.", 'entries' => $entries] + $result;
                }
            }

            $parts = [];
            foreach ($entries as $name) {
                if ($name === '[Content_Types].xml' || preg_match('#^(word/(document|styles|settings|header\d*|footer\d*)\.xml|docProps/(core|app)\.xml)$#', $name) === 1) {
                    $xml = (string) $zip->getFromName($name);
                    if (stripos(substr($xml, 0, 4096), '<!DOCTYPE') !== false) {
                        return ['error' => "{$name} contains a DTD, which is not allowed.", 'entries' => $entries] + $result;
                    }
                    if ($this->dom($xml) === null) {
                        return ['error' => "{$name} is not well-formed XML.", 'entries' => $entries] + $result;
                    }
                    $parts[$name] = $xml;
                }
            }
        } finally {
            $zip->close();
            @unlink($temp);
        }

        return ['ok' => true, 'error' => null, 'entries' => $entries, 'parts' => $parts];
    }

    /**
     * Body paragraphs in order, with their text (w:br → "\n", w:tab → "\t";
     * field codes and deleted revisions excluded) and paragraph style id.
     *
     * @return list<array{text:string, style:?string, align:?string, bold:bool}>
     */
    public function paragraphs(string $documentXml): array
    {
        $dom = $this->dom($documentXml);
        if ($dom === null) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS);
        $paragraphs = [];
        foreach ($xpath->query('//w:body//w:p[not(ancestor::w:txbxContent)]') ?: [] as $paragraph) {
            $style = $xpath->query('./w:pPr/w:pStyle', $paragraph)->item(0);
            $align = $xpath->query('./w:pPr/w:jc', $paragraph)->item(0);
            $runs = $xpath->query('.//w:r[not(ancestor::w:del)][w:t]', $paragraph);
            $bold = $runs->length > 0;
            foreach ($runs as $run) {
                $b = $xpath->query('./w:rPr/w:b', $run)->item(0);
                $bold = $bold && $b instanceof DOMElement && ! in_array($b->getAttributeNS(self::NS, 'val'), ['0', 'false'], true);
            }

            $paragraphs[] = [
                'text' => $this->textOf($paragraph),
                'style' => $style instanceof DOMElement ? $style->getAttributeNS(self::NS, 'val') : null,
                'align' => $align instanceof DOMElement ? $align->getAttributeNS(self::NS, 'val') : null,
                'bold' => $bold,
            ];
        }

        return $paragraphs;
    }

    /** @param array<string,string> $parts @return list<string> distinct font names referenced by runs and styles */
    public function fonts(array $parts): array
    {
        $fonts = [];
        foreach ($parts as $name => $xml) {
            if (! preg_match('#^word/(document|styles|header\d*|footer\d*)\.xml$#', $name)) {
                continue;
            }
            preg_match_all('/<w:rFonts\b[^>]*>/', $xml, $tags);
            foreach ($tags[0] as $tag) {
                preg_match_all('/w:(?:ascii|hAnsi|cs|eastAsia)="([^"]+)"/', $tag, $values);
                array_push($fonts, ...$values[1]);
            }
        }

        return array_values(array_unique(array_map('html_entity_decode', $fonts)));
    }

    /** Field instructions (PAGE, NUMPAGES...) used in headers and footers. @return list<string> */
    public function marginalFields(array $parts): array
    {
        $fields = [];
        foreach ($parts as $name => $xml) {
            if (preg_match('#^word/(header|footer)\d*\.xml$#', $name)) {
                preg_match_all('#<w:instrText[^>]*>\s*([A-Z]+)#', $xml, $m);
                array_push($fields, ...$m[1]);
                preg_match_all('#<w:fldSimple[^>]*w:instr="\s*([A-Z]+)#', $xml, $m);
                array_push($fields, ...$m[1]);
            }
        }

        return array_values(array_unique($fields));
    }

    /** Proofing language set in the document defaults (e.g. "en-GB"). */
    public function language(array $parts): ?string
    {
        preg_match('#<w:docDefaults>.*?<w:lang\b[^>]*w:val="([^"]+)"#s', $parts['word/styles.xml'] ?? '', $m);

        return $m[1] ?? null;
    }

    /**
     * Reasons the text would not be freely editable: protection, locked
     * content controls, text boxes, images or embedded objects, macros.
     *
     * @return list<string>
     */
    public function editabilityProblems(array $opened): array
    {
        $problems = [];
        $settings = $opened['parts']['word/settings.xml'] ?? '';
        $body = implode('', array_intersect_key($opened['parts'], array_flip(array_filter(
            array_keys($opened['parts']),
            fn ($name) => preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name) === 1,
        ))));

        if (preg_match('/<w:(documentProtection|writeProtection)\b/', $settings)) {
            $problems[] = 'the document is protected';
        }
        if (preg_match('/<w:lock\b/', $body)) {
            $problems[] = 'it contains locked content controls';
        }
        if (str_contains($body, '<w:txbxContent')) {
            $problems[] = 'it contains text boxes';
        }
        if (preg_match('/<w:(drawing|pict|object)\b/', $body) || preg_grep('#^word/media/#', $opened['entries'])) {
            $problems[] = 'it contains images or embedded objects';
        }
        if (preg_grep('/vbaProject\.bin$/i', $opened['entries'])) {
            $problems[] = 'it contains macros';
        }

        return $problems;
    }

    /** Core properties (title, creator, keywords...). @return array<string,string> */
    public function coreProperties(array $parts): array
    {
        $properties = [];
        preg_match_all('#<(dc|cp):(title|creator|subject|description|keywords|category|lastModifiedBy)>(.*?)</\1:\2>#s', $parts['docProps/core.xml'] ?? '', $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $properties[$match[2]] = html_entity_decode($match[3], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        if (preg_match('#<Application>(.*?)</Application>#s', $parts['docProps/app.xml'] ?? '', $app)) {
            $properties['application'] = html_entity_decode($app[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return $properties;
    }

    private function textOf(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->namespaceURI !== self::NS) {
                // mc:AlternateContent carries the same content twice (Choice / Fallback): read one.
                $text .= $child->localName === 'AlternateContent'
                    ? ($child->firstElementChild ? $this->textOf($child->firstElementChild) : '')
                    : $this->textOf($child);

                continue;
            }
            $text .= match ($child->localName) {
                't' => $child->textContent,
                'tab', 'ptab' => "\t",
                'br', 'cr' => "\n",
                'noBreakHyphen' => '-',
                // Field codes, deleted text, nested paragraphs (text boxes) and properties are not visible text.
                'instrText', 'delText', 'del', 'pPr', 'rPr', 'txbxContent', 'p', 'fldData' => '',
                default => $this->textOf($child),
            };
        }

        return $text;
    }

    private function dom(string $xml): ?DOMDocument
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }
        $dom = new DOMDocument;

        return @$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE) ? $dom : null;
    }

    private function tempFile(string $bytes): string
    {
        $directory = storage_path('app/tmp');
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.bin2hex(random_bytes(12)).'.docx';
        file_put_contents($path, $bytes);
        @chmod($path, 0600);

        return $path;
    }
}
