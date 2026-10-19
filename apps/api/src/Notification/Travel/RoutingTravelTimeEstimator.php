<?php

declare(strict_types=1);

namespace App\Notification\Travel;

use App\Enum\TravelMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Geocodes the place with Nominatim (cached for a week, the same place recurs) and asks OSRM for the travel time.
 * An OSRM server routes for the one profile it was built with, so each mode has its own: ROUTING_OSRM_URL for the
 * car, ROUTING_OSRM_BIKE_URL and ROUTING_OSRM_FOOT_URL. All are the operator's own; a mode without a server, and
 * public transport (OSRM has no timetables), has no estimate, and only travel times the user set are used.
 */
#[AsAlias(TravelTimeEstimator::class)]
final readonly class RoutingTravelTimeEstimator implements TravelTimeEstimator
{
    /** OSRM's profile names, which servers built for one profile ignore. */
    private const array PROFILES = ['car' => 'driving', 'bike' => 'cycling', 'walk' => 'foot', 'transit' => 'driving'];

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        #[Autowire('%env(default::ROUTING_OSRM_URL)%')]
        private ?string $osrmUrl,
        #[Autowire('%env(default::ROUTING_OSRM_BIKE_URL)%')]
        private ?string $osrmBikeUrl,
        #[Autowire('%env(default::ROUTING_OSRM_FOOT_URL)%')]
        private ?string $osrmFootUrl,
        #[Autowire('%env(default::GEOCODER_NOMINATIM_URL)%')]
        private ?string $nominatimUrl,
    ) {
    }

    public function isAvailable(TravelMode $mode): bool
    {
        return null !== $this->server($mode) && null !== $this->nominatimUrl && '' !== $this->nominatimUrl;
    }

    public function minutes(float $latitude, float $longitude, string $place, \DateTimeImmutable $arriveBy, TravelMode $mode): ?int
    {
        $server = $this->server($mode);
        if (null === $server || !$this->isAvailable($mode) || '' === trim($place)) {
            return null;
        }
        $target = $this->geocode($place);
        if (null === $target) {
            return null;
        }

        try {
            $answer = $this->http->request('GET', \sprintf('%s/route/v1/%s/%F,%F;%F,%F', rtrim($server, '/'), self::PROFILES[$mode->value], $longitude, $latitude, $target[1], $target[0]), [
                'query' => ['overview' => 'false'],
                'timeout' => 5,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Routing failed', ['error' => $e->getMessage()]);

            return null;
        }
        $routes = $answer['routes'] ?? null;
        $duration = \is_array($routes) && \is_array($routes[0] ?? null) ? ($routes[0]['duration'] ?? null) : null;

        return is_numeric($duration) ? (int) ceil((float) $duration / 60) : null;
    }

    private function server(TravelMode $mode): ?string
    {
        $url = match ($mode) {
            TravelMode::Car => $this->osrmUrl,
            TravelMode::Bike => $this->osrmBikeUrl,
            TravelMode::Walk => $this->osrmFootUrl,
            TravelMode::Transit => null,
        };

        return null === $url || '' === $url ? null : $url;
    }

    /**
     * @return array{float, float}|null latitude and longitude
     */
    private function geocode(string $place): ?array
    {
        /** @var array{float, float}|null */
        return $this->cache->get('geocode.'.hash('sha256', mb_strtolower(trim($place))), function (ItemInterface $item) use ($place): ?array {
            $item->expiresAfter(604800);
            try {
                $results = $this->http->request('GET', rtrim((string) $this->nominatimUrl, '/').'/search', [
                    'query' => ['q' => $place, 'format' => 'jsonv2', 'limit' => 1],
                    'headers' => ['User-Agent' => 'Tresso'],
                    'timeout' => 5,
                ])->toArray();
            } catch (ExceptionInterface $e) {
                $this->logger->warning('Geocoding failed', ['error' => $e->getMessage()]);
                $item->expiresAfter(600);

                return null;
            }
            $first = $results[0] ?? null;
            if (!\is_array($first) || !is_numeric($first['lat'] ?? null) || !is_numeric($first['lon'] ?? null)) {
                return null;
            }

            return [(float) $first['lat'], (float) $first['lon']];
        });
    }
}
