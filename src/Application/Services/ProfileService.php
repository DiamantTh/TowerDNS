<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Auth\User;

final readonly class ProfileService
{
    public function __construct(private UserRepositoryInterface $users, private ThemeManager $themes) {}

    public function update(User $user, string $displayName, string $theme, string $locale): void
    {
        $displayName = trim($displayName);
        if (mb_strlen($displayName) > 64) {
            throw new \InvalidArgumentException('Invalid display name.');
        }
        if ($theme !== 'system' && !$this->themes->has($theme)) {
            throw new \InvalidArgumentException('Invalid theme.');
        }
        if (!in_array($locale, SupportedLocales::all(), true)) {
            throw new \InvalidArgumentException('Invalid locale.');
        }
        $this->users->updateProfile($user->id, $displayName, $theme, $locale);
    }
}
