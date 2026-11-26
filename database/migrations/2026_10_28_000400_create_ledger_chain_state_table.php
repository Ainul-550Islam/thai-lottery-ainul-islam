<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tipHash = null;

        if (Schema::hasTable('ledger_transactions')) {
            $tips = DB::table('ledger_transactions as candidate')
                ->leftJoin('ledger_transactions as child', 'child.previous_hash', '=', 'candidate.hash')
                ->whereNull('child.id')
                ->pluck('candidate.hash');

            if ($tips->count() > 1) {
                throw new RuntimeException('Ledger chain has multiple tips; refusing to guess chain state.');
            }

            $candidate = $tips->first();
            if ($candidate !== null) {
                if (! is_string($candidate) || preg_match('/^[a-f0-9]{64}$/i', $candidate) !== 1) {
                    throw new RuntimeException('Ledger chain tip hash is malformed; refusing to initialize chain state.');
                }

                $tipHash = strtolower($candidate);
            }
        }

        Schema::create('ledger_chain_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('tip_hash', 64)->nullable();
            $table->timestamps();
        });

        DB::table('ledger_chain_state')->insert([
            'id' => 1,
            'tip_hash' => $tipHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_chain_state');
    }
};
