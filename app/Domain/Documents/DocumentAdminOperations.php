<?php

namespace App\Domain\Documents;

use App\Models\AdminUser;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\Revision;
use Illuminate\Http\UploadedFile;
use LogicException;

/** Manual document overrides for administrators; every operation creates a new version and is audited. (Owned by the document engine.) */
class DocumentAdminOperations
{
    public function rerender(DocumentVersion $version, ?DocumentTemplate $template, AdminUser $admin): DocumentVersion
    {
        throw new LogicException('Not implemented yet.');
    }

    public function editText(DocumentVersion $version, DocumentModel $model, AdminUser $admin): DocumentVersion
    {
        throw new LogicException('Not implemented yet.');
    }

    /** $format: "pdf" or "docx". */
    public function replaceFile(DocumentVersion $version, string $format, UploadedFile $file, AdminUser $admin): DocumentVersion
    {
        throw new LogicException('Not implemented yet.');
    }

    public function uploadFinal(Order $order, UploadedFile $docx, ?UploadedFile $pdf, AdminUser $admin, ?Revision $revision = null): DocumentVersion
    {
        throw new LogicException('Not implemented yet.');
    }
}
