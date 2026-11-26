<?php

namespace App\Providers;

use App\Http\Middleware\EnforceWorkspaceRole;
use App\Livewire\Hooks\EnforceRoleOnActions;
use App\Services\GoogleCloudAccessTokenProvider;
use App\Support\GenerationDeadline;
use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;
use Livewire\ComponentHookRegistry;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GoogleCloudAccessTokenProvider::class);
        $this->app->singleton(GenerationDeadline::class);
        // Registered before Livewire boots its hook registry, which only wires hooks it already knows.
        ComponentHookRegistry::register(EnforceRoleOnActions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([EnforceWorkspaceRole::class]);

        Storage::extend('gcs', function ($app, array $config): LaravelFilesystemAdapter {
            if (! ($config['project_id'] ?? null) || ! ($config['bucket'] ?? null)) {
                throw new \RuntimeException('Google Cloud Storage project and bucket must be configured.');
            }

            $client = new StorageClient([
                'projectId' => $config['project_id'],
                'credentialsFetcher' => $app->make(GoogleCloudAccessTokenProvider::class),
            ]);
            $adapter = new GoogleCloudStorageAdapter(
                $client->bucket($config['bucket']),
                $config['path_prefix'] ?? '',
                new UniformBucketLevelAccessVisibility,
            );

            return new LaravelFilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });

        Storage::extend('azure', function ($app, array $config): LaravelFilesystemAdapter {
            if (! ($config['container'] ?? null) || (! ($config['connection_string'] ?? null) && ! (($config['account'] ?? null) && ($config['key'] ?? null)))) {
                throw new \RuntimeException('Azure Blob Storage container and credentials must be configured.');
            }

            $connection = ($config['connection_string'] ?? null) ?: implode(';', array_filter([
                ($config['url'] ?? null) ? 'BlobEndpoint='.$config['url'] : 'DefaultEndpointsProtocol=https',
                'AccountName='.$config['account'],
                'AccountKey='.$config['key'],
                ($config['url'] ?? null) ? null : 'EndpointSuffix=core.windows.net',
            ]));
            $adapter = new AzureBlobStorageAdapter(
                BlobServiceClient::fromConnectionString($connection)->getContainerClient($config['container']),
                $config['prefix'] ?? '',
                visibilityHandling: AzureBlobStorageAdapter::ON_VISIBILITY_IGNORE,
            );
            $disk = new LaravelFilesystemAdapter(new Filesystem($adapter), $adapter, $config);
            // Service SAS links for one blob: read-only for viewing, create and write only for a browser upload in blocks.
            $disk->buildTemporaryUrlsUsing(fn (string $path, \DateTimeInterface $expiration, array $options) => $adapter->temporaryUrl($path, $expiration, new Config($options)));
            $disk->buildTemporaryUploadUrlsUsing(fn (string $path, \DateTimeInterface $expiration) => [
                'url' => $adapter->temporaryUrl($path, $expiration, new Config(['permissions' => 'cw'])),
                'headers' => [],
                'blocks' => true,
            ]);

            return $disk;
        });
    }
}
