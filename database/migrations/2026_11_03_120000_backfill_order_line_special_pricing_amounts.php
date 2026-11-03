<?php

use App\Services\Checkout\CartLineSpecialBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CartLineSpecialBackfill::class)->run();
    }

    public function down(): void
    {
    }
};
