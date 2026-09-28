<?php

namespace b10k\componentmap\services;

use Craft;
use craft\base\Component;
use craft\fields\Matrix;
use craft\models\EntryType;

/**
 * Reads the parts of the Craft content model the map needs — sections, entry
 * types, Matrix fields, category groups — into plain arrays for the
 * Craft-free {@see GraphBuilder}.
 *
 * Schema only: no entries are queried, so it is fast on any size of site and
 * safe on any environment.
 */
class CraftStructure extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $entries = Craft::$app->getEntries();

        $sections = [];
        foreach ($entries->getAllSections() as $section) {
            $templates = [];
            foreach ($section->getSiteSettings() as $siteSettings) {
                if ($siteSettings->hasUrls && $siteSettings->template) {
                    $templates[] = $siteSettings->template;
                }
            }
            $sections[] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'templates' => array_values(array_unique($templates)),
                'entryTypes' => array_map(static fn(EntryType $t) => $t->handle, $section->getEntryTypes()),
            ];
        }

        $entryTypes = [];
        foreach ($entries->getAllEntryTypes() as $type) {
            $fields = [];
            foreach ($type->getFieldLayout()->getCustomFields() as $field) {
                if ($field instanceof Matrix) {
                    $fields[] = $field->handle;
                }
            }
            $entryTypes[] = [
                'handle' => $type->handle,
                'name' => $type->name,
                'fields' => $fields,
            ];
        }

        $fields = [];
        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof Matrix) {
                $fields[] = [
                    'handle' => $field->handle,
                    'name' => $field->name,
                    'entryTypes' => array_map(static fn(EntryType $t) => $t->handle, $field->getEntryTypes()),
                ];
            }
        }

        $categoryGroups = [];
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $templates = [];
            foreach ($group->getSiteSettings() as $siteSettings) {
                if ($siteSettings->hasUrls && $siteSettings->template) {
                    $templates[] = $siteSettings->template;
                }
            }
            if ($templates !== []) {
                $categoryGroups[] = [
                    'handle' => $group->handle,
                    'name' => $group->name,
                    'templates' => array_values(array_unique($templates)),
                ];
            }
        }

        return [
            'sections' => $sections,
            'entryTypes' => $entryTypes,
            'fields' => $fields,
            'categoryGroups' => $categoryGroups,
        ];
    }
}
