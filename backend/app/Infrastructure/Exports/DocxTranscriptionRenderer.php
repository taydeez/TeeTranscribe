<?php

namespace App\Infrastructure\Exports;

final class DocxTranscriptionRenderer
{
    public function render(string $title, string $transcript): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transcription-docx-');
        if ($path === false) {
            throw new \RuntimeException('Could not create DOCX temporary file.');
        }
        $zip = new \ZipArchive;
        $opened = false;
        try {
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create DOCX archive.');
            }
            $opened = true;
            $parts = [
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
                '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
                'word/document.xml' => $this->document($title, $transcript),
            ];
            foreach ($parts as $name => $content) {
                if (! $zip->addFromString($name, $content)) {
                    throw new \RuntimeException('Could not write DOCX content.');
                }
            }
            if (! $zip->close()) {
                throw new \RuntimeException('Could not finish DOCX archive.');
            }
            $opened = false;
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException('Could not read DOCX export.');
            }

            return $bytes;
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($path);
        }
    }

    private function document(string $title, string $transcript): string
    {
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS('w', 'document', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xml->startElement('w:body');
        foreach (array_merge([$title], preg_split('/\R/u', $transcript) ?: []) as $index => $line) {
            $xml->startElement('w:p');
            $xml->startElement('w:r');
            if ($index === 0) {
                $xml->startElement('w:rPr');
                $xml->writeElement('w:b');
                $xml->startElement('w:sz');
                $xml->writeAttribute('w:val', '32');
                $xml->endElement();
                $xml->endElement();
            }
            $xml->startElement('w:t');
            $xml->writeAttribute('xml:space', 'preserve');
            $xml->text(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $line) ?? '');
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
