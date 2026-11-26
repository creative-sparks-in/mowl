<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreativeLibraryPermissionsTest extends TestCase
{
    public function test_postgres_app_role_can_use_creative_assets_with_row_level_security_on(): void
    {
        $url = getenv('PRODUCT_HUB_TEST_DATABASE_URL');
        if (! $url) {
            $this->markTestSkipped('Set PRODUCT_HUB_TEST_DATABASE_URL to a disposable PostgreSQL test database.');
        }

        $this->assertStringEndsWith('_test', trim(parse_url($url, PHP_URL_PATH), '/'));
        config(['database.connections.creative_permissions' => [
            'driver' => 'pgsql', 'url' => $url, 'charset' => 'utf8', 'prefix' => '',
        ]]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('creative_permissions');
        $db = DB::connection();
        $schema = 'creative_permissions_'.bin2hex(random_bytes(6));
        $db->beginTransaction();

        try {
            $db->statement("CREATE SCHEMA {$schema}");
            $db->statement("SET LOCAL search_path TO {$schema}");
            $db->statement("DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN CREATE ROLE marketing_owl_app; END IF; END $$");
            $db->statement("GRANT USAGE ON SCHEMA {$schema} TO marketing_owl_app");
            foreach (['workspaces', 'products', 'users'] as $table) {
                $db->statement("CREATE TABLE {$table} (id bigint PRIMARY KEY)");
                $db->table($table)->insert(['id' => 1]);
            }

            $migration = require database_path('migrations/2026_11_26_100000_create_creative_assets_table.php');
            $migration->up();
            $db->statement('SET LOCAL ROLE marketing_owl_app');

            $id = $db->table('creative_assets')->insertGetId([
                'workspace_id' => 1, 'product_id' => 1, 'uploaded_by_user_id' => 1, 'kind' => 'video', 'disk' => 'azure',
                'path' => 'creatives/1/1/a/original.mov', 'original_name' => 'reel.mov', 'title' => 'Reel', 'mime_type' => 'video/quicktime',
                'size_bytes' => 5_000_000_000, 'tags' => json_encode(['UGC']), 'status' => 'pending',
            ]);
            $this->assertSame(1, $db->table('creative_assets')->where('id', $id)->update(['status' => 'ready']));
            $this->assertSame(1, $db->table('creative_assets')->whereJsonContains('tags', 'UGC')->count());
            $this->assertSame(1, $db->table('creative_assets')->where('id', $id)->delete());
            $db->statement('RESET ROLE');
            $this->assertTrue((bool) $db->scalar("SELECT relrowsecurity FROM pg_class WHERE oid = '{$schema}.creative_assets'::regclass"));
            $this->assertFalse((bool) $db->scalar("SELECT has_table_privilege('public', '{$schema}.creative_assets', 'SELECT')"));
        } finally {
            $db->rollBack();
            DB::purge('creative_permissions');
            DB::setDefaultConnection($previous);
        }
    }
}
