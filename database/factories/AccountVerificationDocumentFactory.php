<?php

namespace Database\Factories;

use App\Models\AccountVerificationDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AccountVerificationDocument>
 *
 * AccountVerificationDocument is the account-facing VIEW of the canonical
 * kyc_documents row — same table, same columns, same casts. So the column
 * definition is inherited from KycDocumentFactory rather than copied: a second
 * copy would drift the moment a column is added to the canonical table.
 *
 * Only $model is overridden, because Laravel resolves a factory's model from the
 * factory's own class name and would otherwise hand back a KycDocument.
 */
class AccountVerificationDocumentFactory extends KycDocumentFactory
{
    /** @var class-string<AccountVerificationDocument> */
    protected $model = AccountVerificationDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return parent::definition();
    }
}
