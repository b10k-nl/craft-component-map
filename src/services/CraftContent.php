<?php

namespace b10k\componentmap\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;

/**
 * Reads which entries contain which blocks, straight from Craft's ownership
 * table — one query, however deep the nesting — and describes the few entries
 * a command shows.
 *
 * Read-only. Canonical content only: drafts, revisions and deleted elements
 * are left out. Turn it off with the `readEntries` setting.
 */
class CraftContent extends Component
{
    private ?ContentIndex $index = null;

    public function index(): ContentIndex
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $entries = Craft::$app->getEntries();
        $types = [];
        foreach ($entries->getAllEntryTypes() as $type) {
            $types[(int)$type->id] = (string)$type->handle;
        }
        $sections = [];
        foreach ($entries->getAllSections() as $section) {
            $sections[(int)$section->id] = (string)$section->handle;
        }

        $canonical = static fn(string $alias): array => [
            "$alias.dateDeleted" => null,
            "$alias.draftId" => null,
            "$alias.revisionId" => null,
        ];

        $nested = [];
        $rows = (new Query())
            ->select(['id' => 'o.elementId', 'owner' => 'o.ownerId', 'type' => 'e.typeId'])
            ->from(['o' => Table::ELEMENTS_OWNERS])
            ->innerJoin(['e' => Table::ENTRIES], '[[e.id]] = [[o.elementId]]')
            ->innerJoin(['el' => Table::ELEMENTS], '[[el.id]] = [[o.elementId]]')
            ->innerJoin(['ow' => Table::ELEMENTS], '[[ow.id]] = [[o.ownerId]]')
            ->where($canonical('el'))
            ->andWhere($canonical('ow'))
            ->all();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $nested[$id]['type'] = $types[(int)$row['type']] ?? '';
            $nested[$id]['owners'][] = (int)$row['owner'];
        }

        $pages = [];
        $rows = (new Query())
            ->select(['id' => 'e.id', 'section' => 'e.sectionId', 'type' => 'e.typeId'])
            ->from(['e' => Table::ENTRIES])
            ->innerJoin(['el' => Table::ELEMENTS], '[[el.id]] = [[e.id]]')
            ->where(['not', ['e.sectionId' => null]])
            ->andWhere($canonical('el'))
            ->all();
        foreach ($rows as $row) {
            $pages[(int)$row['id']] = [
                'section' => $sections[(int)$row['section']] ?? '',
                'type' => $types[(int)$row['type']] ?? '',
            ];
        }

        return $this->index = new ContentIndex($nested, $pages);
    }

    /**
     * Title, URL and status of these elements, in the order given. Elements
     * are looked up in the primary site first, then any site they exist in.
     *
     * @param int[] $ids
     * @return array<int, array{id: int, title: string, url: ?string, cpUrl: ?string, status: string, where: string}>
     */
    public function describe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byClass = [];
        foreach ((new Query())->select(['id', 'type'])->from(Table::ELEMENTS)->where(['id' => $ids])->all() as $row) {
            $byClass[(string)$row['type']][] = (int)$row['id'];
        }

        $primary = Craft::$app->getSites()->getPrimarySite()->handle;
        $found = [];
        foreach ($byClass as $class => $classIds) {
            if (!class_exists($class) || !is_subclass_of($class, ElementInterface::class)) {
                continue;
            }
            /** @var class-string<ElementInterface> $class */
            $query = $class::find()->id($classIds)->status(null)->site('*')->unique()->preferSites([$primary]);
            foreach ($query->all() as $element) {
                $found[(int)$element->id] = [
                    'id' => (int)$element->id,
                    'title' => self::label($element),
                    'url' => $element->getUrl(),
                    'cpUrl' => $element->getCpEditUrl(),
                    'status' => (string)$element->getStatus(),
                    'where' => $element instanceof Entry ? (string)($element->getSection()->handle ?? '') : $class::displayName(),
                ];
            }
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $out[] = $found[$id];
            }
        }
        return $out;
    }

    /**
     * A name for an element people recognise, also when its entry type has no
     * title field: the title, else the section name for a single, else the
     * slug, else the id.
     */
    private static function label(ElementInterface $element): string
    {
        $title = trim((string)$element->title);
        if ($title !== '') {
            return $title;
        }
        if ($element instanceof Entry) {
            $section = $element->getSection();
            if ($section !== null && $section->type === \craft\models\Section::TYPE_SINGLE) {
                return (string)$section->name;
            }
        }
        $slug = trim((string)$element->slug);
        return $slug !== '' && !str_starts_with($slug, '__') ? $slug : '#' . $element->id;
    }
}
