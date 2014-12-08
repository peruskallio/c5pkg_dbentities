<?php

/**
 * This class provides the ability for packages to automatically install their
 * DB schema from the entity classes written in PHP. This also creates the
 * proxy classes for the entities and handles database migrations.
 * 
 * There is currently no information for concrete5 developers on the best
 * practices for package development so it is likely that this will become
 * obsolete once there is proper information available.
 * 
 * However, for now, we'll use this method for ease of use and 
 * 
 * This class cannot be used in any other context than the concrete5 context.
 * This is because it extends the concrete5's internal Package class which is
 * usually not available outside of the concrete5 context. Also, this class
 * uses the DIR_PACKAGES constant that is internally set in concrete5.
 * 
 * @author Antti Hukkanen <antti.hukkanen(at)mainiotech.fi>
 * @copyright 2014 Mainio Tech Ltd.
 * @license MIT
 */

namespace Mainio\C5\Entity;

use Config;
use Database;

class Package extends \Concrete\Core\Package\Package
{

    // The directory within the package where proxies are saved
    protected $DIRNAME_PROXIES = 'proxies';
    
    // Package-specific entity manager
    protected $entityManager;

    public function install($data = null)
    {
        // Only generate the proxy classes when we're developing the package.
        if (Config::get('app.package_dev_mode')) {
            $this->generateProxyClasses();
        }
        $pkg = parent::install();
        $this->installDatabase();
        return $pkg;
    }

    public function upgrade()
    {
        // Only generate the proxy classes when we're developing the package.
        if (Config::get('app.package_dev_mode')) {
            $this->generateProxyClasses();
        }
        parent::upgrade();
        $this->installDatabase();
    }

    public function generateProxyClasses()
    {
        $classes = $this->getPackageEntities();
        
        if (sizeof($classes) > 0) {
            // First create the proxies directory if it does not already exist.
            $proxiesDir = $this->getPackageProxiesPath();
            if (!is_dir($proxiesDir)) {
                if (file_exists($proxiesDir)) {
                    throw new Exception(sprintf(
                        "A file exists in place of the pacakge proxies directory. " .
                        "Please remove the file named '%s' from the package.",
                        $this->DIRNAME_PROXIES
                    ));
                }
                @mkdir($proxiesDir);
                if (is_dir($proxiesDir)) {
                    @chmod($proxiesDir, DIRECTORY_PERMISSIONS_MODE);
                } else {
                    throw new Exception(
                        "Could not create the proxies directory. " .
                        "Please check the file permissions of the package folder."
                    );
                }
            }

            $em = $this->getEntityManager();
            $cmf = $em->getMetadataFactory();

            $metadatas = array();
            foreach ($classes as $cls) {
                $metadatas[] = $cmf->getMetadataFor($cls);
            }

            // Generate the proxy classes
            $pf = $em->getProxyFactory();
            $pf->generateProxyClasses($metadatas);
        }
    }

    public function getEntityManager()
    {
        if (!isset($this->entityManager)) {
            $conn = Database::getActiveConnection();
            $em = $conn->getEntityManager();
            $config = $em->getConfiguration();
            $this->modifyEntityManagerConfiguration($config);

            $this->entityManager = \Doctrine\ORM\EntityManager::create($conn, $config);
        }
        return $this->entityManager;
    }

    /** 
     * Installs the database tables according to the entity schema definitions.
     * This will not install any existing tables but it will migrate those
     * tables to matche the current schema definitions for the classes.
     */
    public function installDatabase()
    {
        $this->dropObsoleteDatabaseTables();

        $classes = $this->getPackageEntities();

        if (sizeof($classes) > 0) {
            // We need to create the SchemaDiff manually here because we want
            // to avoid calling the execution for two separate SchemaDiff
            // objects (one for missing tables and one for new ones).
            // Also, while $tool->createSchema($missingEntities) works great
            // for new tables, $tool->updateSchema($updateEntities) would
            // actually delete all the DB tables that the DB contains and are
            // not part of the entity tables passed to the function. Therefore,
            // we do this manually here.
            $em = $this->getEntityManager();
            $conn = $em->getConnection();
            $sm = $conn->getSchemaManager();
            $cmf = $em->getMetadataFactory();

            $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
            $comparator = new \Doctrine\DBAL\Schema\Comparator();

            $metadatas = array();
            foreach ($classes as $cls) {
                $metadatas[] = $cmf->getMetadataFor($cls);
            }

            // NOTE: $newSchema != $toSchema because $toSchema would actually
            // contain each and every table in the database. We'll only need
            // to traverse the $newSchema for the purposes of the desired
            // functionality but we also need $fromSchema to check whether
            // the table already exists and also to get the current schema
            // for that table to figure out the changes to the new table.
            $fromSchema = $sm->createSchema();
            $newSchema = $tool->getSchemaFromMetadata($metadatas);

            $newTables = array();
            $changedTables = array();
            foreach ($newSchema->getTables() as $newTable) {
                // Check if the table already exists
                if ($fromSchema->hasTable($newTable->getName())) {
                    $diff = $comparator->diffTable($fromSchema->getTable($newTable->getName()), $newTable);
                    if ($diff) {
                        $changedTables[] = $diff;
                    }
                } else {
                    $newTables[] = $newTable;
                }
            }

            if (sizeof($newTables) > 0 || sizeof($changedTables) > 0) {
                // If we have new or changed tables (or both), we'll gather
                // these DB changes into a SchemaDiff object and get all the
                // necessary DB migration queries for that diff object.
                // Finally, those queries are executed against the DB.
                $schemaDiff = new \Doctrine\DBAL\Schema\SchemaDiff($newTables, $changedTables);
                $platform = $conn->getDatabasePlatform();
                $migrateSql = $schemaDiff->toSql($platform);
                foreach ($migrateSql as $sql) {
                    $conn->executeQuery($sql);
                }
            }
        }
    }

    /**
     * Do not normally call this during a package uninstall. Save this for 
     * special occasions.
     * 
     * This drops all the tables related to this package based on the database
     * tables of the package entities.
     */
    public function uninstallDatabase()
    {
        $classes = $this->getPackageEntities();

        if (sizeof($classes) > 0) {
            $em = $this->getEntityManager();
            $conn = $em->getConnection();
            $sm = $conn->getSchemaManager();
            $cmf = $em->getMetadataFactory();

            $tool = new \Doctrine\ORM\Tools\SchemaTool($em);

            $metadatas = array();
            foreach ($classes as $cls) {
                $metadatas[] = $cmf->getMetadataFor($cls);
            }
            $newSchema = $tool->getSchemaFromMetadata($metadatas);

            // We'll let Doctrine resolve the correct drop order for the tables
            // because of which we use the schema migration method to drop the
            // tables. By letting Doctrine resolve the drop order we avoid
            // DB server constraint violation errors (e.g. in MySQL).
            $fromSchema = $sm->createSchema();
            $toSchema = clone $fromSchema;

            foreach ($newSchema->getTables() as $newTable) {
                if ($toSchema->hasTable($newTable->getName())) {
                    $toSchema->dropTable($newTable->getName());
                }
            }

            $sqls = $fromSchema->getMigrateToSql($toSchema, $conn->getDatabasePlatform());
            foreach ($sqls as $sql) {
                $conn->executeQuery($sql);
            }
        }
    }

    /**
     * Drops all the database tables that
     * a) are prefixed with the camelcased package handle of this package
     * b) are not linked to any existing entity within this package
     */
    public function dropObsoleteDatabaseTables()
    {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();
        $sm = $conn->getSchemaManager();
        $cmf = $em->getMetadataFactory();

        $existing = array();

        $classes = $this->getPackageEntities();
        foreach ($classes as $cls) {
            $md = $cmf->getMetadataFor($cls);
            $existing[] = $md->getTableName();
        }

        $fromSchema = $sm->createSchema();
        $toSchema = clone $fromSchema;

        $prefix = camelcase($this->getPackageHandle());
        foreach ($fromSchema->getTables() as $tbl) {
            if (strpos($tbl->getName(), $prefix) === 0 && !in_array($tbl->getName(), $existing)) {
                $toSchema->dropTable($tbl->getName());
            }
        }

        $sqls = $fromSchema->getMigrateToSql($toSchema, $conn->getDatabasePlatform());
        foreach ($sqls as $sql) {
            $conn->executeQuery($sql);
        }
    }

    public function getPackageEntities()
    {
        //Get classes from the MetaDataFactory
         $classes = Array();
        /** @var ClassMetadata $metaData */
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metaData) {
            $classes[] = $metaData->getName();
        }
        return $classes;
    }

    public function getPackageProxiesPath()
    {
        return $this->getPackagePath() . '/' . $this->DIRNAME_PROXIES;
    }

    protected function modifyEntityManagerConfiguration(\Doctrine\ORM\Configuration $config)
    {
        $config->setProxyDir($this->getPackageProxiesPath());
    }

  

}
