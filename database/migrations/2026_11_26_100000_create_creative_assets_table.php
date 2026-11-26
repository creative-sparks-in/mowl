<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creatives a team made elsewhere (images and reels), uploaded per product for finding, previewing,
 * downloading and sharing. Kept apart from media_assets, which feed generation inputs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10);
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('poster_path', 500)->nullable();
            $table->string('original_name', 255);
            $table->string('title', 200);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('aspect_ratio', 10)->nullable();
            $table->string('channel', 30)->nullable();
            $table->string('stage', 30)->nullable();
            $table->json('tags')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['product_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE creative_assets ENABLE ROW LEVEL SECURITY');
            DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN GRANT SELECT, INSERT, UPDATE, DELETE ON creative_assets TO marketing_owl_app; GRANT USAGE, SELECT ON SEQUENCE creative_assets_id_seq TO marketing_owl_app; IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'creative_assets' AND policyname = 'marketing_owl_app_full_access') THEN EXECUTE 'CREATE POLICY marketing_owl_app_full_access ON creative_assets FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)'; END IF; END IF; END $$");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_assets');
    }
};
