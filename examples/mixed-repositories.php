<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Micx\Vcs\MixVcsManager;

const DEVELOPMENT_MODE = true;

$mockDirectory = __DIR__ . '/../mock-repos/repo1';
if (!is_dir($mockDirectory)) {
    mkdir($mockDirectory, 0770, true);
}

// Derselbe Manager für echte Repositories und lokale Mock-Verzeichnisse.
$manager = new MixVcsManager(
    sharedDirectory: '/vcs-share',
    connector: 'rabbitmq://user:password@rabbitmq:5672/micx.vcs.v1',
    storageTemplate: '{repo}/{branch}',
);
$manager->addMockDir('git@github.com:example/repo1.git', 'main', $mockDirectory);
$manager->setAllowMockDir(DEVELOPMENT_MODE);

// Mit aktivem Mock-Mapping greift Repo 1 direkt auf das Projektverzeichnis zu.
$repo1 = $manager->getRepository('git@github.com:example/repo1.git', 'main');
echo $repo1->getDirectory(), PHP_EOL;
$repo1->commit('Update local content');
$repo1->sync();

// Repo 2: Zielverzeichnis wird explizit unterhalb des Shared Directory gesetzt.
// getRepository() und getDirectory() haben keinen Netzwerk- oder Git-Seiteneffekt.
$repo2 = $manager->getRepository(
    'git@github.com:example/repo2.git',
    'main',
    'customer/repo2-main',
);
$directory = $repo2->getDirectory(); // /vcs-share/customer/repo2-main
echo $directory, PHP_EOL;

// Ein separater Initialisierungslauf kann den Checkout zuerst bereitstellen.
$repo2->pull();
file_put_contents($directory . '/hello.txt', "Hello from shared volume\n");
// Commit-Identität gilt ab jetzt nur für dieses Repository-Handle.
$repo2->setCommitUser('Jane Doe', 'jane@example.com');
$repo2->commit('Update shared content');
$repo2->sync(); // Pull + Push; ohne automatischen Commit.
