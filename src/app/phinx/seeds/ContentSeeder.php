<?php

declare(strict_types=1);

use App\Domain\Content\RecordTypes;
use App\Libraries\Content\Publishing;
use App\Models\Content\RecordRepository;
use Phinx\Seed\AbstractSeed;

/**
 * Sample content for local development (docs/content.md): a published post,
 * a published page made of blocks, and a draft post, so the Posts and Pages
 * screens and the site API have something in them from the first run. Every
 * title starts "Sample:" so none is mistaken for real content. Written
 * through the app's own Publishing, so each has its revision and slug
 * history like anything staff publish.
 *
 *     docker compose exec php vendor/bin/phinx seed:run -s UserSeeder -s ContentSeeder
 *
 * Refuses to run outside development, as UserSeeder does. Safe to run again:
 * a slug that exists is left alone. A project replaces these with samples of
 * its own record types (KAW Meetup's seeds sample events).
 */
final class ContentSeeder extends AbstractSeed
{
    /** @return list<string> */
    public function getDependencies(): array
    {
        return ['UserSeeder'];
    }

    public function run(): void
    {
        $environment = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '';

        if (!in_array($environment, ['development', 'local', 'testing'], true)) {
            throw new RuntimeException("ContentSeeder refuses to run with APP_ENV={$environment}: it is for local development only.");
        }

        /** @var Closure(array<string,mixed>=): Psr\Container\ContainerInterface $build */
        $build = require dirname(__DIR__, 3) . '/config/container-builder.php';
        $container = $build();
        $publishing = $container->get(Publishing::class);
        $records = $container->get(RecordRepository::class);
        $types = $container->get(RecordTypes::class);
        $editor = (int) $this->getAdapter()->getConnection()->query("SELECT id FROM users WHERE email = 'admin@example.test'")->fetchColumn();

        echo "\n";

        foreach ([
            ['posts', true, [
                'slug' => 'sample-first-post',
                'title' => 'Sample: a first post',
                'summary' => 'A sample published post for local development.',
                'body' => "Sample data from ContentSeeder, not real content.\n\n## A section\n\nPosts are Markdown: **emphasis**, lists and [links](/about/).\n\n- One\n- Two",
            ]],
            ['pages', true, [
                'slug' => 'sample-about',
                'title' => 'Sample: about us',
                'summary' => 'A sample page of blocks for local development.',
                'body' => [
                    ['type' => 'heading', 'level' => 2, 'text' => 'Who we are'],
                    ['type' => 'text', 'markdown' => 'Sample data from ContentSeeder, not real content. Pages are made of **blocks**.'],
                    ['type' => 'callout', 'tone' => 'note', 'markdown' => 'Edit this page on the Pages screen.'],
                    ['type' => 'faq', 'items' => [['question' => 'Is this real?', 'answer' => 'No: it is sample data.']]],
                    ['type' => 'cta', 'label' => 'Read the posts', 'path' => '/blog/', 'note' => null],
                ],
            ]],
            ['posts', false, [
                'slug' => 'sample-draft',
                'title' => 'Sample: a draft post',
                'summary' => 'A sample draft for local development.',
                'body' => 'Sample data from ContentSeeder. Publish it from the Posts screen to see it in the site API.',
            ]],
        ] as [$name, $publish, $input]) {
            $type = $types->find($name) ?? throw new RuntimeException("No record type {$name}.");

            if ($records->slugOwner($type, $input['slug']) !== null) {
                echo "  kept     {$name}/{$input['slug']} (already exists)\n";

                continue;
            }

            $record = $publishing->create($type, $input);

            if ($publish) {
                $publishing->publish($type, $record->id, $editor);
            }

            echo "  created  {$name}/{$record->slug}" . ($publish ? ' (published)' : ' (draft)') . "\n";
        }

        echo "\n";
    }
}
