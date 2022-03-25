<?php
/*
 *  $Id: Migration.php 1080 2007-02-10 18:17:08Z jwage $
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

/**
 * Doctrine_Migration
 *
 * this class represents a database view
 *
 * @package     Doctrine
 * @subpackage  Migration
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.doctrine-project.org
 * @since       1.0
 * @version     $Revision: 1080 $
 * @author      Jonathan H. Wage <jwage@mac.com>
 */
class Doctrine_Migration
{
    /**
     * @var string
     */
    protected string $_migrationTableName = 'migration_version';

    /**
     * @var bool
     */
    protected bool $_migrationTableCreated = false;

    /**
     * @var Doctrine_Connection
     */
    protected Doctrine_Connection $_connection;

    /**
     * @var string
     */
    protected string $_migrationClassesDirectory = '';

    /**
     * @var array
     */
    protected array $_migrationClasses = [];

    /**
     * @var ReflectionClass
     */
    protected ReflectionClass $_reflectionClass;

    /**
     * @var array
     */
    protected array $_errors = [];

    /**
     * @var Doctrine_Migration_Process
     */
    protected Doctrine_Migration_Process $_process;

    /**
     * @var array
     */
    protected static array $_migrationClassesForDirectories = [];

    /**
     * Specify the path to the directory with the migration classes.
     * The classes will be loaded and the migration table will be created if it
     * does not already exist
     *
     * @param string|null $directory  The path to your migrations directory
     * @param mixed       $connection The connection name or instance to use for this migration
     *
     * @throws ReflectionException
     * @return void
     */
    public function __construct(?string $directory = null, $connection = null)
    {
        $this->_reflectionClass = new ReflectionClass('Doctrine_Migration_Base');

        if (is_null($connection)) {
            $this->_connection = Doctrine_Manager::connection();
        } elseif (is_string($connection)) {
            $this->_connection = Doctrine_Manager::getInstance()->getConnection($connection);
        } else {
            $this->_connection = $connection;
        }

        $this->_process = new Doctrine_Migration_Process($this);

        if ($directory !== null) {
            $this->_migrationClassesDirectory = $directory;

            $this->loadMigrationClassesFromDirectory();
        }
    }

    /**
     * @return Doctrine_Connection
     */
    public function getConnection() : Doctrine_Connection
    {
        return $this->_connection;
    }

    /**
     * @param Doctrine_Connection $conn
     *
     * @return void
     */
    public function setConnection(Doctrine_Connection $conn)
    {
        $this->_connection = $conn;
    }

    /**
     * Get the migration classes directory
     *
     * @return string $migrationClassesDirectory
     */
    public function getMigrationClassesDirectory() : string
    {
        return $this->_migrationClassesDirectory;
    }

    /**
     * Get the table name for storing the version number for this migration instance
     *
     * @return string $migrationTableName
     */
    public function getTableName() : string
    {
        return $this->_migrationTableName;
    }

    /**
     * Set the table name for storing the version number for this migration instance
     *
     * @param string $tableName
     *
     * @return void
     */
    public function setTableName(string $tableName)
    {
        $this->_migrationTableName = $this->getConnection()->formatter->getTableName($tableName);
    }

    /**
     * Load migration classes from the passed directory. Any file found with a .php
     * extension will be passed to the loadMigrationClass()
     *
     * @param string|null $directory Directory to load migration classes from
     *
     * @throws ReflectionException
     * @return void
     */
    public function loadMigrationClassesFromDirectory(?string $directory = null)
    {
        $directory = $directory ?? $this->_migrationClassesDirectory;

        $classesToLoad = [];
        $classes = get_declared_classes();

        foreach ((array)$directory as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            if (isset(self::$_migrationClassesForDirectories[$dir])) {
                foreach (self::$_migrationClassesForDirectories[$dir] as $num => $className) {
                    $this->_migrationClasses[$num] = $className;
                }
            }

            foreach ($it as $file) {
                $info = pathinfo($file->getFileName());
                if (isset($info['extension']) && $info['extension'] == 'php') {
                    require_once($file->getPathName());

                    $array = array_diff(get_declared_classes(), $classes);
                    $className = end($array);

                    if ($className) {
                        $e = explode('_', $file->getFileName());
                        $timestamp = $e[0];

                        $classesToLoad[$timestamp] = ['className' => $className, 'path' => $file->getPathName()];
                    }
                }
            }
        }

        ksort($classesToLoad, SORT_NUMERIC);

        foreach ($classesToLoad as $class) {
            $this->loadMigrationClass($class['className'], $class['path']);
        }
    }

    /**
     * Load the specified migration class name in to this migration instances queue of
     * migration classes to execute. It must be a child of Doctrine_Migration in order
     * to be loaded.
     *
     * @param string      $name
     * @param string|null $path
     *
     * @throws ReflectionException
     * @return bool
     */
    public function loadMigrationClass(string $name, ?string $path = null) : bool
    {
        $class = new ReflectionClass($name);

        while ($class->isSubclassOf($this->_reflectionClass)) {
            $class = $class->getParentClass();

            if ($class === false) {
                break;
            }
        }

        if ($class === false) {
            return false;
        }

        if (empty($this->_migrationClasses)) {
            $classMigrationNum = 1;
        } else {
            $nums = array_keys($this->_migrationClasses);
            $num = end($nums);
            $classMigrationNum = $num + 1;
        }

        $this->_migrationClasses[$classMigrationNum] = $name;

        if ($path) {
            $dir = dirname($path);
            self::$_migrationClassesForDirectories[$dir][$classMigrationNum] = $name;
        }

        return true;
    }

    /**
     * Get all the loaded migration classes. Array where key is the number/version
     * and the value is the class name.
     *
     * @return array $migrationClasses
     */
    public function getMigrationClasses() : array
    {
        return $this->_migrationClasses;
    }

    /**
     * Set the current version of the database
     *
     * @param int $number
     *
     * @return void
     */
    public function setCurrentVersion(int $number)
    {
        if ($this->hasMigrated()) {
            $this->getConnection()->execute("UPDATE {$this->getTableName()} SET version = $number");
        } else {
            $this->getConnection()->execute("INSERT INTO {$this->getTableName()} (version) VALUES ($number)");
        }
    }

    /**
     * Get the current version of the database
     *
     * @return int $version
     */
    public function getCurrentVersion() : int
    {
        $this->_createMigrationTable();

        $result = $this->getConnection()->fetchColumn("SELECT version FROM {$this->getTableName()}");

        return $result[0] ?? 0;
    }

    /**
     * Returns true/false for whether or not this database has been migrated in the past
     *
     * @return bool $migrated
     */
    public function hasMigrated() : bool
    {
        $this->_createMigrationTable();

        $result = $this->getConnection()->fetchColumn("SELECT version FROM {$this->getTableName()}");

        return isset($result[0]);
    }

    /**
     * Gets the latest possible version from the loaded migration classes
     *
     * @return int $latestVersion
     */
    public function getLatestVersion() : int
    {
        $versions = array_keys($this->_migrationClasses);
        rsort($versions);

        return $versions[0] ?? 0;
    }

    /**
     * Get the next incremented version number based on the latest version number
     * using getLatestVersion()
     *
     * @return int $nextVersion
     */
    public function getNextVersion() : int
    {
        return $this->getLatestVersion() + 1;
    }

    /**
     * Get the next incremented class version based on the loaded migration classes
     *
     * @return int $nextMigrationClassVersion
     */
    public function getNextMigrationClassVersion() : int
    {
        if (empty($this->_migrationClasses)) {
            return 1;
        } else {
            $nums = array_keys($this->_migrationClasses);

            return end($nums) + 1;
        }
    }

    /**
     * Perform a migration process by specifying the migration number/version to
     * migrate to. It will automatically know whether you are migrating up or down
     * based on the current version of the database.
     *
     * @param int  $to     Version to migrate to
     * @param bool $dryRun Whether or not to run the migrate process as a dry run
     *
     * @throws Doctrine_Exception
     * @return int|false $to       Version number migrated to
     */
    public function migrate($to = null, $dryRun = false)
    {
        $this->clearErrors();

        $this->_createMigrationTable();

        $this->getConnection()->beginTransaction();

        try {
            // If nothing specified then lets assume we are migrating from
            // the current version to the latest version
            if ($to === null) {
                $to = $this->getLatestVersion();
            }

            $this->_doMigrate($to);
        } catch (Exception $e) {
            $this->addError($e);
        }

        if ($this->hasErrors()) {
            try {
                $this->getConnection()->rollback();
            } catch (PDOException $e) {
                // Hiding transaction error
                // $this->addError($e);
            }

            if ($dryRun) {
                return false;
            } else {
                $this->_throwErrorsException();
            }
        } elseif ($dryRun) {
            try {
                $this->getConnection()->rollback();
            } catch (PDOException $e) {
                // Hiding transaction error
                // $this->addError($e);
            }

            if ($this->hasErrors()) {
                return false;
            } else {
                return $to;
            }
        } else {
            try {
                $this->getConnection()->commit();
            } catch (PDOException $e) {
                // Hiding transaction error
                // $this->addError($e);
            }

            $this->setCurrentVersion($to);

            return $to;
        }

        return false;
    }

    /**
     * Run the migration process but rollback at the very end. Returns true or
     * false for whether or not the migration can be ran
     *
     * @param int|null $to
     *
     * @throws Doctrine_Exception
     * @return false|int $success
     */
    public function migrateDryRun(?int $to = null)
    {
        return $this->migrate($to, true);
    }

    /**
     * Get the number of errors
     *
     * @return int $numErrors
     */
    public function getNumErrors() : int
    {
        return count($this->_errors);
    }

    /**
     * Get all the error exceptions
     *
     * @return array $errors
     */
    public function getErrors() : array
    {
        return $this->_errors;
    }

    /**
     * Clears the error exceptions
     *
     * @return void
     */
    public function clearErrors()
    {
        $this->_errors = [];
    }

    /**
     * Add an error to the stack. Excepts some type of Exception
     *
     * @param Exception $e
     *
     * @return void
     */
    public function addError(Exception $e)
    {
        $this->_errors[] = $e;
    }

    /**
     * Whether or not the migration instance has errors
     *
     * @return bool
     */
    public function hasErrors() : bool
    {
        return $this->getNumErrors() > 0;
    }

    /**
     * Get instance of migration class for number/version specified
     *
     * @param int $num
     *
     * @throws Doctrine_Migration_Exception $e
     *
     * @return Doctrine_Migration_Base
     */
    public function getMigrationClass(int $num) : Doctrine_Migration_Base
    {
        if (isset($this->_migrationClasses[$num])) {
            $className = $this->_migrationClasses[$num];

            return new $className();
        }

        throw new Doctrine_Migration_Exception('Could not find migration class for migration step: ' . $num);
    }

    /**
     * Throw an exception with all the errors trigged during the migration
     *
     * @throws Doctrine_Migration_Exception $e
     * @return void
     */
    protected function _throwErrorsException()
    {
        $messages = [];
        $num = 0;

        foreach ($this->getErrors() as $error) {
            $num++;
            $messages[] = ' Error #' . $num . ' - ' .
                          $error->getMessage() . PHP_EOL .
                          $error->getTraceAsString() . PHP_EOL;
        }

        $title = $this->getNumErrors() . ' error(s) encountered during migration';
        $message = $title . PHP_EOL;
        $message .= str_repeat('=', strlen($title)) . PHP_EOL;
        $message .= implode(PHP_EOL, $messages);

        throw new Doctrine_Migration_Exception($message);
    }

    /**
     * Do the actual migration process
     *
     * @param int $to
     *
     * @throws Doctrine_Exception
     * @return int $to
     */
    protected function _doMigrate(int $to) : int
    {
        $from = $this->getCurrentVersion();

        if ($from == $to) {
            throw new Doctrine_Migration_Exception('Already at version # ' . $to);
        }

        $direction = $from > $to ? 'down' : 'up';

        if ($direction === 'up') {
            for ($i = $from + 1; $i <= $to; $i++) {
                $this->_doMigrateStep($direction, $i);
            }
        } else {
            for ($i = $from; $i > $to; $i--) {
                $this->_doMigrateStep($direction, $i);
            }
        }

        return $to;
    }

    /**
     * Perform a single migration step. Executes a single migration class and
     * processes the changes
     *
     * @param string $direction Direction to go, 'up' or 'down'
     * @param int    $num
     *
     * @return void
     */
    protected function _doMigrateStep(string $direction, int $num)
    {
        try {
            $migration = $this->getMigrationClass($num);

            $method = 'pre' . $direction;
            $migration->$method();

            if (method_exists($migration, $direction)) {
                $migration->$direction();
            } elseif (method_exists($migration, 'migrate')) {
                $migration->migrate($direction);
            }

            if ($migration->getNumChanges() > 0) {
                $changes = $migration->getChanges();

                if ($direction == 'down' && method_exists($migration, 'migrate')) {
                    $changes = array_reverse($changes);
                }

                foreach ($changes as $value) {
                    [$type, $change] = $value;

                    $funcName = 'process' . Doctrine_Inflector::classify($type);

                    if (method_exists($this->_process, $funcName)) {
                        try {
                            $this->_process->$funcName($change);
                        } catch (Exception $e) {
                            $this->addError($e);
                        }
                    } else {
                        throw new Doctrine_Migration_Exception(
                            sprintf('Invalid migration change type: %s', $type)
                        );
                    }
                }
            }

            $method = 'post' . $direction;
            $migration->$method();
        } catch (Exception $e) {
            $this->addError($e);
        }
    }

    /**
     * Create the migration table and return true. If it already exists it will
     * silence the exception and return false
     *
     * @return bool $created Whether or not the table was created. Exceptions
     *                          are silenced when table already exists
     */
    protected function _createMigrationTable() : bool
    {
        if ($this->_migrationTableCreated) {
            return true;
        }

        $this->_migrationTableCreated = true;

        try {
            $this->getConnection()->export->createTable(
                $this->getTableName(),
                ['version' => ['type' => 'integer', 'size' => 11]]
            );

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
