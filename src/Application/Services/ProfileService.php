<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Auth\User;

final readonly class ProfileService
{
    public function __construct(private UserRepositoryInterface $users, private ThemeManager $themes) {}

    /** @param array<string, mixed> $input */
    public function update(User $user, array $input): void
    {
        $displayName = $this->optional($input, 'display_name', 64);
        $theme       = (string) ($input['theme'] ?? '');
        if ($theme !== 'system' && !$this->themes->has($theme)) {
            throw new \InvalidArgumentException('Invalid theme.');
        }
        $language = UserPreferences::normalizeLanguage((string) ($input['language'] ?? ''));
        $locale   = UserPreferences::normalizeLocale((string) ($input['locale'] ?? ''));
        $timezone = UserPreferences::normalizeTimezone((string) ($input['timezone'] ?? ''));
        if ($language === null || $locale === null || $timezone === null) {
            throw new \InvalidArgumentException('Invalid user preferences.');
        }
        $profile = ['display_name' => $displayName, 'theme' => $theme, 'language' => $language, 'locale' => $locale, 'timezone' => $timezone];
        foreach (['first_name' => 100, 'last_name' => 100, 'phone' => 64, 'mobile' => 64, 'street' => 255, 'street2' => 255, 'postal_code' => 32, 'city' => 128, 'region' => 128, 'external_reference' => 255] as $field => $maximum) {
            $profile[$field] = $this->optional($input, $field, $maximum);
        }
        $profile['alternate_email'] = $this->optional($input, 'alternate_email', 254);
        if ($profile['alternate_email'] !== null && filter_var($profile['alternate_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid alternate e-mail.');
        }
        $country = strtoupper($this->optional($input, 'country', 2) ?? '');
        if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException('Invalid country.');
        }
        $profile['country'] = $country === '' ? null : $country;
        $this->users->updateProfile($user->id, $profile);
    }

    /** @param array<string, mixed> $input */
    private function optional(array $input, string $field, int $maximum): ?string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if (mb_strlen($value) > $maximum) {
            throw new \InvalidArgumentException("Invalid {$field}.");
        }
        return $value === '' ? null : $value;
    }
}
