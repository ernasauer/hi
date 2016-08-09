<?php
declare(strict_types=1);

namespace LotGD\Core;

use Doctrine\ORM\ {
    EntityManager,
    EntityManagerInterface,
    Mapping\AnsiQuoteStrategy,
    Tools\Setup,
    Tools\SchemaTool
};
use Monolog\ {
    Logger,
    Handler\RotatingFileHandler
};
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;

use LotGD\Core\ {
    ComposerManager,
    BootstrapInterface,
    Exceptions\InvalidConfigurationException
};

/**
 * The entry point for constructing a properly configured LotGD Game object.
 */
class Bootstrap
{
    private $game;
    private $libraryConfigurationManager = [];
    private $annotationDirectories = [];

    /**
     * Create a new Game object, with all the necessary configuration.
     * @return Game The newly created Game object.
     */
    public static function createGame(string $cwd = null): Game
    {
        $game = new self();
        return $game->getGame($cwd);
    }

    /**
     * Starts the game kernel with the most important classes and returns the object
     * @return Game
     */
    public function getGame(string $cwd = null): Game
    {
        $composer = $this->createComposerManager($cwd);
        $this->libraryConfigurationManager = $this->createLibraryConfigurationManager($composer);

        $config = $this->createConfiguration($cwd);
        $logger = $this->createLogger($config, "lotgd");

        $pdo = $this->connectToDatabase($config);
        $entityManager = $this->createEntityManager($pdo);

        $this->game = new Game($config, $logger, $entityManager);

        return $this->game;
    }

    /**
     * Creates a library configuration manager
     * @param ComposerManager $composerManager
     * @return \LotGD\Core\LibraryConfigurationManager
     */
    protected function createLibraryConfigurationManager(
        ComposerManager $composerManager
    ): LibraryConfigurationManager {
        return new LibraryConfigurationManager($composerManager);
    }

    /**
     * Connects to a database using pdo
     * @param \LotGD\Core\Configuration $config
     * @return \PDO
     */
    protected function connectToDatabase(Configuration $config): \PDO
    {
        return new \PDO($config->getDatabaseDSN(), $config->getDatabaseUser(), $config->getDatabasePassword());
    }

    /**
     * Creates and returns an instance of ComposerManager
     * @return ComposerManager
     */
    protected function createComposerManager(string $cwd = null): ComposerManager
    {
        $composer = new ComposerManager($cwd);
        return $composer;
    }

    /**
     * Returns a configuration object reading from the file located at the path stored in $cwd/config/lotgd.yml.
     * @return \LotGD\Core\Configuration
     * @throws InvalidConfigurationException
     */
    protected function createConfiguration(string $cwd = null): Configuration
    {
        if ($cwd == null) {
            $cwd = getcwd();
        }

        if (empty($configFilePath)) {
            $configFilePath = implode(DIRECTORY_SEPARATOR, [$cwd, "config", "lotgd.yml"]);
        }

        if ($configFilePath === false || strlen($configFilePath) == 0 || is_file($configFilePath) === false) {
            throw new InvalidConfigurationException("Invalid or missing configuration file: {$configFilePath}.");
        }

        $config = new Configuration($configFilePath);
        return $config;
    }

    /**
     * Returns a logger instance
     * @param type $name
     * @return LoggerInterface
     */
    protected function createLogger(Configuration $config, string $name): LoggerInterface
    {
        $logger = new Logger($name);
        // Add lotgd as the prefix for the log filenames.
        $logger->pushHandler(new RotatingFileHandler($config->getLogPath() . DIRECTORY_SEPARATOR . $name, 14));

        $v = Game::getVersion();
        $logger->info("Bootstrap constructing game (Daenerys 🐲{$v}).");

        return $logger;
    }

    /**
     * Creates the EntityManager using the pdo connection given in it's argument
     * @param \PDO $pdo
     * @return EntityManagerInterface
     */
    protected function createEntityManager(\PDO $pdo): EntityManagerInterface
    {
        $this->annotationDirectories = $this->generateAnnotationDirectories();
        $configuration = Setup::createAnnotationMetadataConfiguration($this->annotationDirectories, true);

        // Set a quote
        $configuration->setQuoteStrategy(new AnsiQuoteStrategy());

        // Create entity manager
        $entityManager = EntityManager::create(["pdo" => $pdo], $configuration);

        // Create Schema and update database if needed
        $metaData = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->updateSchema($metaData);

        return $entityManager;
    }

    /**
     * Is used to get all directories used to generate annotations.
     * @param array $bootstrapClasses
     * @return array
     */
    protected function generateAnnotationDirectories(): array
    {
        // Read db annotations from our own model files.
        $directories = [__DIR__ . DIRECTORY_SEPARATOR . 'Models'];

        // Get additional annotation directories from library configs.
        $libraryDirectories = $this->libraryConfigurationManager->getEntityDirectories();

        return array_merge($directories, $libraryDirectories);
    }

    /**
     * Adds Symfony/Console commands to the provided application from configured libraries.
     * @param Application $application
     */
    public function addDaenerysCommands(Application $application)
    {
        foreach ($this->libraryConfigurationManager->getConfigurations() as $config) {
            $commands = $config->getDaenerysCommands();
            foreach ($commands as $command) {
                $application->add(new $command($game));
            }
        }
    }
}
