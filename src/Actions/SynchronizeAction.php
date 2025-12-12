<?php

namespace Kenepa\TranslationManager\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Kenepa\TranslationManager\Commands\SynchronizeTranslationsCommand;
use Kenepa\TranslationManager\Helpers\TranslationScanner;
use Kenepa\TranslationManager\TranslationManagerPlugin;
use Spatie\TranslationLoader\LanguageLine;

class SynchronizeAction extends Action
{
    public static function make(?string $name = null): static
    {
        return parent::make($name)
            ->label(__('translation-manager::translations.synchronize'))
            ->icon('heroicon-o-arrow-path-rounded-square');
    }

    /**
     * Runs the synchronization process for the translations.
     * Enhanced to merge locale values into existing translation keys.
     */
    public static function synchronize(?SynchronizeTranslationsCommand $command = null): array
    {
        // Extract all translation groups, keys and text
        $groupsAndKeys = TranslationScanner::scan();

        $result = [];
        $result['total_count'] = 0;
        $result['updated_count'] = 0;

        // Get configured locales to filter out unwanted ones
        $configuredLocales = collect(TranslationManagerPlugin::get()->getAvailableLocales())
            ->pluck('code')
            ->toArray();

        // Find and delete old LanguageLines that no longer exist in the translation files
        $groupsInFiles = array_unique(array_column($groupsAndKeys, 'group'));
        $keysInFiles = array_column($groupsAndKeys, 'key');

        $result['deleted_count'] = LanguageLine::query()
            ->where(function ($query) use ($groupsInFiles, $keysInFiles) {
                $query->whereNotIn('group', $groupsInFiles)
                    ->orWhereNotIn('key', $keysInFiles);
            })
            ->delete();

        // Create new LanguageLines or update existing ones
        foreach ($groupsAndKeys as $groupAndKey) {
            $startTime = microtime(true);

            // Filter out unconfigured locales
            $filteredText = array_filter(
                $groupAndKey['text'],
                fn ($locale) => in_array($locale, $configuredLocales),
                ARRAY_FILTER_USE_KEY
            );

            // Skip if no configured locales remain
            if (empty($filteredText)) {
                continue;
            }

            $existingItem = LanguageLine::where('group', $groupAndKey['group'])
                ->where('key', $groupAndKey['key'])
                ->first();

            if (! $existingItem) {
                // Create new translation key
                LanguageLine::create([
                    'group' => $groupAndKey['group'],
                    'key' => $groupAndKey['key'],
                    'text' => $filteredText,
                ]);

                $result['total_count'] += 1;

                $runTime = number_format((microtime(true) - $startTime) * 1000, 2);
                $command?->components()->twoColumnDetail(
                    $groupAndKey['group'] . '.' . $groupAndKey['key'],
                    "<fg=gray>{$runTime} ms</> <fg=green;options=bold>CREATED</>"
                );
            } else {
                // Merge locale values into existing record
                $currentText = $existingItem->text ?? [];
                $merged = array_merge($currentText, $filteredText);

                // Check if anything changed
                $hasChanges = count(array_diff_assoc($merged, $currentText)) > 0
                    || count(array_diff_assoc($currentText, $merged)) > 0;

                if ($hasChanges) {
                    $existingItem->text = $merged;
                    $existingItem->save();

                    $result['updated_count'] += 1;

                    $runTime = number_format((microtime(true) - $startTime) * 1000, 2);
                    $command?->components()->twoColumnDetail(
                        $groupAndKey['group'] . '.' . $groupAndKey['key'],
                        "<fg=gray>{$runTime} ms</> <fg=yellow;options=bold>UPDATED</>"
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Runs the synchronization process for a page.
     */
    public static function run(): void
    {
        $result = static::synchronize();

        Notification::make()
            ->title(__('translation-manager::translations.synchronization-success', ['count' => $result['total_count']]))
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->send();

        if (isset($result['updated_count']) && $result['updated_count'] > 0) {
            Notification::make()
                ->title('Updated ' . $result['updated_count'] . ' existing translation(s) with new locale values')
                ->icon('heroicon-o-arrow-path')
                ->iconColor('warning')
                ->send();
        }

        if ($result['deleted_count'] > 0) {
            Notification::make()
                ->title(__('translation-manager::translations.synchronization-deleted', ['count' => $result['deleted_count']]))
                ->icon('heroicon-o-trash')
                ->send();
        }
    }
}