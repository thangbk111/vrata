<?php

namespace App\Console\Commands;

use App\Exceptions\DataFormatException;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Webpatser\Uuid\Uuid;

class ParseServices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:parse';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse API documentation of services';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array
     */
    protected $config;

    /**
     * ParseServices constructor.
     * @param Client $client
     * @param array $config
     */
    public function __construct(Client $client, $config = null)
    {
        parent::__construct();
        $this->client = $client;
        $this->config = $config ?: app('config')->get('gateway');
    }

    /**
     * @return Collection
     */
    private function getResources()
    {
        return collect($this->config['services'])->map(function($settings, $serviceId) {
            $this->info('** Parsing ' . $serviceId);

            $docRoot = $settings['doc_point'] ?? $this->config['global']['doc_point'];
            $url = $settings['url'] ?? 'http://' . $serviceId . '.' . $this->config['global']['domain'];

            $response = $this->client->request('GET', $url . $docRoot);

            $data = json_decode((string) $response->getBody(), true);
            if ($data === null) throw new DataFormatException('Unable to get JSON response from ' . $serviceId);
            if (! isset($data['apis'])) throw new DataFormatException($serviceId . ' doesn\'t contain API data');

            return collect($data['apis'])->map(function ($api) use ($url, $serviceId, $docRoot) {
                return array_merge($api, [
                    'url' => $url,
                    'service' => $serviceId,
                    'docRoot' => $docRoot,
                ]);
            });
        })->flatten(1);
    }

    /**
     * @param Collection $resources
     * @return array
     */
    private function getPaths(Collection $resources)
    {
        return $resources->reduce(function($carry, $resource) {
            $pathElements = explode('.', $resource['path']);
            $resource['path'] = reset($pathElements);
            $this->line('Processing API endpoint: ' . $resource['url'] . $resource['path']);

            $response = $this->client->request('GET', $resource['url'] . $resource['docRoot'] . $resource['path']);
            $data = json_decode((string) $response->getBody(), true);
            if ($data === null) throw new DataFormatException('Unable to get JSON response from ' . $resource['serviceId']);

            // Inject service details
            $apis = collect($data['apis'])->map(function ($api) use ($resource) {
                return array_merge($api, $resource);
            });

            return array_merge($carry, $apis->toArray());
        }, []);
    }

    /**
     * @param Collection $paths
     * @return array
     */
    private function getEndpoints(Collection $paths)
    {
        return collect($paths)->reduce(function($carry, $route) {
            $pathElements = explode('.', $route['path']);
            $route['path'] = reset($pathElements);

            foreach ($route['operations'] as $realOperation) {
                $carry[] = [
                    'id' => (string)Uuid::generate(4),
                    'method' => $realOperation['method'],
                    'endpoint' => $route['url'] . $route['path'],
                    'path' => $this->config['global']['prefix'] . $route['path']
                ];
            }

            return $carry;
        }, []);
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws DataFormatException
     */
    public function handle()
    {
        $output = $this->getEndpoints(
            collect($this->getPaths(
                $this->getResources()
            ))
        );

        $this->info('Dumping route data to JSON file');

        if (Storage::exists('routes.json')) {
            if (! $this->confirm('File exists, we are about to overwrite it. Are you sure? [y|N]')) exit;
        }

        Storage::put('routes.json', json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Finished!');
    }
}