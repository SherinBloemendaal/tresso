<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        registerBundles as private registerConfiguredBundles;
    }

    /**
     * Registers config/bundles.php plus the optional, git-ignored config/bundles.local.php, through which add-ons such
     * as Tresso Cloud are installed without the open code depending on them.
     */
    public function registerBundles(): iterable
    {
        // Passed through untouched: Symfony also yields internal entries here. The type is KernelInterface's contract.
        /** @var iterable<BundleInterface> $configured */
        $configured = $this->registerConfiguredBundles();
        yield from $configured;

        $localBundles = $this->getConfigDir().'/bundles.local.php';
        if (!is_file($localBundles)) {
            return;
        }

        /** @var array<class-string<BundleInterface>, array<string, bool>> $contents */
        $contents = require $localBundles;
        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
