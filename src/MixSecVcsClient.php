<?php
declare(strict_types=1);

namespace Micx\Vcs;

use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\Exception\RepositoryNotFoundException;
use Micx\Vcs\Exception\AccessDeniedException;
use Micx\Vcs\Transport\TransportFactory;
use Micx\Vcs\Transport\RpcTransport;
use Micx\Vcs\Helper\Workspace\RpcClient;
use Micx\Vcs\Repository\VcsRepository;
use Micx\Vcs\Repository\VcsMockRepository;
use Micx\Vcs\Repository\VcsRemoteRepository;

final class MixSecVcsClient
{
    /** @var array<string, string> */
    private array $mockDirectories = [];
    private bool $allowMockDir = false;
    private ?RpcClient $client = null;
    private ?string $rabbitMqUrl = null;
    private float $timeout;

    /**
     * Manages local mocks and real Git repositories through a single public API.
     * An existing RpcTransport or rabbitmq:// URL enables remote checkout.
     * null, mock, file and mock:// or file:// selectors disable remote access.
     * With a remote connector, sharedDirectory must be explicitly set and exist.
     * deferSharedDirectoryValidation delays that check until a remote repository is requested.
     * The connection from a URL is opened lazily on the first real checkout.
     *
     * Example:
     * $manager = new MixSecVcsClient();
     * $manager->setAllowMockDir(true);
     * $manager->addMockDir('git@github.com:example/repo1.git', 'main', '/tmp/demo');
     * $repository = $manager->getRepository('git@github.com:example/repo1.git', 'main');
     *
     * @throws InternalException For an unsupported connector URI scheme.
     * @see VcsRepository
     */
    public function __construct(
        private ?string $sharedDirectory = null,
        RpcTransport|string|null $connector = null,
        float $timeout = 60.0,
        bool $deferSharedDirectoryValidation = false,
        private string $storageTemplate = '{repo}/{branch}',
    ) {
        if ($this->storageTemplate === '' || !str_contains($this->storageTemplate, '{repo}') || !str_contains($this->storageTemplate, '{branch}')) {
            throw new \InvalidArgumentException('Storage template must contain {repo} and {branch}.');
        }
        if ($sharedDirectory !== null) {
            if ($sharedDirectory === '' || $sharedDirectory[0] !== '/') {
                throw new InternalException(
                    ErrorCode::SHARED_DIRECTORY_INVALID,
                    'Shared Directory muss ein absoluter Verzeichnispfad sein.',
                    'configure'
                );
            }
            $this->sharedDirectory = rtrim($sharedDirectory, '/') ?: '/';
        }
        if ($connector instanceof RpcTransport) {
            $this->client = new RpcClient($connector, $timeout);
        } elseif (is_string($connector) && TransportFactory::validate($connector)) {
            // Die Factory prüft die URL hier nur syntaktisch: Mock-Hits bleiben offline.
            $this->rabbitMqUrl = $connector;
        }


        // Remote-Zugriff setzt ein ausdrücklich konfiguriertes, gemeinsames Mount voraus.
        if (!$deferSharedDirectoryValidation && ($this->client !== null || $this->rabbitMqUrl !== null)) {
            $this->assertSharedDirectory();
        }

        $this->timeout = $timeout;
    }

    /** Enable or disable local directory mappings. @see self::addMockDir() */
    public function setAllowMockDir(bool $allowed): void
    {
        $this->allowMockDir = $allowed;
    }

    /**
     * Map a repository identifier and branch to an existing local directory.
     * Mappings never make unregistered local paths accessible.
     *
     * Example: $manager->addMockDir('demo', 'main', '/tmp/demo');
     *
     * @see self::setAllowMockDir()
     */
    public function addMockDir(string $repository, string $branch, string $directory): void
    {
        if ($repository === '' || $branch === '') {
            throw new \InvalidArgumentException('Repository and branch must not be empty');
        }
        // Registration must not access mappings that may never be used.
        $this->mockDirectories[$this->key($repository, $branch)] = $directory;
    }

    /**
     * Liefert ein Repository-Objekt ohne Git- oder Netzwerkzugriff.
     * Für Remote-Repositories wird das gemeinsame Verzeichnis geprüft.
     * Bei aktivem Mock-Mapping wird lokal geroutet, sonst zum Remote-Backend.
     * Ein vorhandenes Verzeichnis ist ohne Pull direkt verfügbar.
     * Der optionale relative Verzeichnispfad wird vom Client festgelegt.
     * Ohne Angabe gilt das Storage-Template des Managers (Standard: {repo}/{branch}).
     * Fehlt der Checkout, kann ein anderer Prozess pull() ausführen.
     *
     * Beispiel: $repo = $manager->getRepository('git@github.com:org/app.git', 'main');
     * Danach: $directory = $repo->getDirectory(); // falls vorhanden
     *
     * @throws RepositoryNotFoundException Wenn ohne Connector kein Mock existiert.
     * @see VcsRepository
     */
    public function getRepository(string $repository, string $branch = 'main', ?string $directory = null): VcsRepository
    {
        if ($repository === '' || $branch === '') {
            throw new \InvalidArgumentException('Repository and branch must not be empty');
        }

        $key = $this->key($repository, $branch);
        if ($this->allowMockDir && isset($this->mockDirectories[$key])) {
            return new VcsMockRepository($this->mockDirectories[$key], $branch);
        }

        if ($this->client === null && $this->rabbitMqUrl === null) {
            throw new RepositoryNotFoundException(
                ErrorCode::MOCK_REPOSITORY_NOT_FOUND,
                "Kein Mock-Repository für '$repository' auf Branch '$branch' registriert.",
                'getRepository',
                ['repository' => $repository, 'branch' => $branch]
            );
        }

        // In Production sind nur echte Remote-URLs erlaubt.
        // Ein lokaler Pfad oder file:// darf niemals per Worker aufgelöst werden.
        $isSshUrl = preg_match('~^ssh://[^/\\s@]+@?[^/\\s]+(?:/[^\\s]+)+$~iD', $repository) === 1;
        $isScpUrl = preg_match('~^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[^\\s]+$~D', $repository) === 1;
        $isHttpUrl = preg_match('~^https://[^/\\s@]+(?:/[^\\s]+)+$~iD', $repository) === 1;
        $isGitUrl = preg_match('~^git://[^/\\s]+(?:/[^\\s]+)+$~iD', $repository) === 1;
        if (!$isSshUrl && !$isScpUrl && !$isHttpUrl && !$isGitUrl) {
            throw new \InvalidArgumentException(
                'Remote-Repository muss eine Git-SSH-, HTTPS- oder git://-URL sein; lokale Pfade sind nicht erlaubt.'
            );
        }

        // Handle und Verzeichnis werden rein clientseitig bestimmt; kein I/O.
        // Auch der RabbitMQ-Transport wird erst beim ersten pull() erzeugt.
        return new VcsRemoteRepository(
            fn (): RpcClient => $this->getClient(),
            $repository,
            $branch,
            $this->sharedDirectory,
            $directory ?? $this->resolveDirectory($repository, $branch)
        );
    }

    /**
     * Erstellt den Remote-Transport beim ersten realen Repository-Zugriff.
     * Die Factory prüft Broker-Verbindung und Channel vor dem Checkout.
     *
     * @throws ConnectionException When the broker cannot be reached.
     * @throws InternalException When no remote connector is configured.
     * @see self::getRepository()
     */
    private function getClient(): RpcClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if ($this->rabbitMqUrl === null) {
            throw new InternalException(
                ErrorCode::WORKER_CONFIGURATION,
                'Für ein echtes Repository ist eine rabbitmq://-URL oder ein RpcTransport erforderlich.',
                'getRepository'
            );
        }

        $this->client = new RpcClient(
            TransportFactory::connect($this->rabbitMqUrl),
            $this->timeout
        );

        return $this->client;
    }

    /**
     * Validiert das gemeinsame Dateisystem, bevor ein Remote-Connector genutzt wird.
     * Das Volume muss am selben Mount-Pfad für den aufrufenden Service verfügbar sein.
     *
     * @throws InternalException Wenn das Shared Directory fehlt oder nicht lesbar ist.
     * @see self::getRepository()
     */
    private function assertSharedDirectory(): void
    {
        if ($this->sharedDirectory === null || !is_dir($this->sharedDirectory)
            || !is_readable($this->sharedDirectory)) {
            throw new InternalException(
                ErrorCode::SHARED_DIRECTORY_INVALID,
                'Shared Directory für Remote-VCS fehlt oder ist nicht lesbar: '
                    . ($this->sharedDirectory ?? '(nicht angegeben)'),
                'configure'
            );
        }
    }

    /**
     * Resolves the repository checkout path locally, without file or network access.
     * Example: with {repo}/{branch}, org/site.git on main becomes site/main.
     *
     * @see self::getRepository()
     */
    private function resolveDirectory(string $repository, string $branch): string
    {
        $name = preg_replace('~\.git$~i', '', basename(str_replace(':', '/', $repository)));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Cannot derive repository name from URL.');
        }

        return strtr($this->storageTemplate, ['{repo}' => $name, '{branch}' => $branch]);
    }

    private function key(string $repository, string $branch): string
    {
        return json_encode([$repository, $branch], JSON_THROW_ON_ERROR);
    }
}
