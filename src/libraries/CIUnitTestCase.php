<?php

/*
 * fooStack, CIUnit for CodeIgniter
 * Copyright (c) 2008-2009 Clemens Gruenberger
 * Released under the MIT license, see:
 * http://www.opensource.org/licenses/mit-license.php
 */

trait CIUnit_Assert
{
    public static function assertRedirects($ciOutput, $location, $message = 'Failed to assert redirect')
    {
        $haystack = $ciOutput->get_headers();
        $needle = array("Location: " . site_url($location), true);
        $constraint = new PHPUnit_Framework_Constraint_TraversableContains($needle, true);

        self::assertThat($haystack, $constraint, $message);
    }
}

/**
 * Extending the default phpUnit Framework_TestCase Class
 * providing eg. fixtures, custom assertions, utilities etc.
 */
class CIUnit_TestCase extends PHPUnit_Framework_TestCase
{
    use CIUnit_Assert;

    /**
     * An associative array of table names. The order of the fixtures
     * determines the loading and unloading sequence of the fixtures. This is
     * to help account for foreign key restraints in databases.
     *
     * For example:
     * $tables = array(
     *                'group' => 'group',
     *                'user' => 'user',
     *                'user_group' => 'user_group'
     *                'table_a' => 'table_a_01'
     *            );
     *
     * Note: To test different data scenarios for a single database, create
     * different fixtures.
     *
     * For example:
     * $tables = array(
     *                'table_a' => 'table_a_02'
     *            );
     *
     * @var array
     */
    protected $tables = array();

    // ------------------------------------------------------------------------

    /**
     * The CodeIgniter Framework Instance
     *
     * @var object
     */
    public $CI;

    // ------------------------------------------------------------------------

    /**
     * Constructor
     *
     * @param    string $name
     * @param    array $data
     * @param    string $dataName
     */
    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->CI = &get_instance();

        log_message('debug', get_class($this) . ' CIUnit_TestCase initialized');
    }

    /**
     * Set Up
     *
     * This method will run before every test.
     *
     * @return void
     *
     * @author Eric Jones
     */
    protected function setUp()
    {
        // Only run if the $tables attribute is set.
        if (!empty($this->tables)) {
            $this->dbfixt($this->tables);
        }
    }

    /**
     * Tear Down
     *
     * This method will run after every test.
     *
     * @return void
     *
     * @author Eric Jones
     */
    protected function tearDown()
    {
        // Only run if the $tables attribute is set.
        if (!empty($this->tables)) {
            $this->dbfixt_unload($this->tables);
        }
    }

    /**
     * Loads a MongoDB Fixture from a JSON encoded text file
     * @param $filename - Name and path to the file. Should include an _id, and will need to be valid against its $type reference
     * @param $ref - A CINX/MongoDB reference (ie object db & id, or cdoc)
     * @param $type - The Type of Document: PROJECT, BOM-ITEM-QTY, etc. Will be used for template validation.
     * @returns  MongoDoc object of the newly created document.
     * @TODO - Ugly dependence: support library using higher level support library (CxUtil, MongoDocFactory
     */
    protected function addFixture($filename, $ref, $type)
    {
        // Create fixture object; delete if old test left one lying around
        $this->delFixture($ref, $type);
        if (!file_exists($filename)) {
            throw new Exception("Can not add fixture; did not find fixture JSON doc [$filename]");
        }
        $json = json_decode(file_get_contents($filename));
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Can not add fixture; given JSON doc [$filename] contains invalid JSON");
        }
        $doc = MongoDocFactory::MongoDoc($ref, $type, true);
        $response = CxUtil::getResponseObject();
        $doc->setDoc($json);
        $doc->save($response);
        if ($response->response->message !== "OK") {
            throw new Exception("Could not save fixture file [$filename]: " . $response->response->message);
        }
        return $doc;
    }

    /**
     * Remove the given fixture from the database
     * @param $ref - A CINX/MongoDB reference (ie object db & id, or cdoc)
     * @param $type - The Type of Document: PROJECT, BOM-ITEM-QTY, etc.
     * @TODO - Ugly dependence: support library using higher level support library (CxUtil, MongoDocFactory
     */
    protected function delFixture($ref, $type)
    {
        $response = CxUtil::getResponseObject();
        $doc = MongoDocFactory::MongoDoc($ref, $type, true);
        if ($doc()) {
            // Copy for use in testing
            $doc->delete( $response );
        }
        return $doc;
    }

    /**
     * loads a database fixture
     * for each given fixture, we look up the yaml file and insert that into the corresponding table
     * names are by convention
     * 'users' -> look for 'users_fixt.yml' fixture: 'fixtures/users_fixt.yml'
     * table is assumed to be named 'users'
     * dbfixt can have multiple strings as arguments, like so:
     * $this->dbfixt('users', 'items', 'prices');
     */
    protected function dbfixt($table_fixtures)
    {
        if (is_array($table_fixtures)) {
            $this->load_fixt($table_fixtures);
        } else {
            $table_fixtures = func_get_args();
            $this->load_fixt($table_fixtures);
        }

        /**
         * This is to allow the Unit Tester to specifiy different fixutre files for
         * a given table. An example would be the testing of two different senarios
         * of data in the database.
         *
         * @see CIUnitTestCase::tables
         */
        foreach ($table_fixtures as $table => $fixt) {
            $fixt_name = $fixt . '_fixt';
            $table = is_int($table) ? $fixt : $table;

            if (!empty($this->$fixt_name)) {
                CIUnit::$fixture->load($table, $this->$fixt_name);
            } else {
                die("The fixture {$fixt_name} failed to load properly\n");
            }

        }

        log_message('debug', 'Table fixtures "' . join('", "', $table_fixtures) . '" loaded');
    }

    /**
     * DBFixt Unload
     *
     * Since there may be foreign key dependencies in the database, we can't just
     * truncate tables in random order. This method attempts to truncate the
     * tables by reversing the order of the $table attribute.
     *
     * @param    array $table_fixtures Typically this will be the class attribute $table.
     * @param    boolean $reverse Should the method reverse the $table_fixtures array
     * before the truncating the tables?
     *
     * @return void
     *
     * @see CIUnitTestCase::table
     *
     * @uses CIUnit::fixture
     * @uses Fixture::unload()
     *
     * @author Eric Jones <eric.web.email@gmail.com>
     */
    protected function dbfixt_unload(array $table_fixtures, $reverse = true)
    {
        // Should we reverse the order of loading?
        // Helps with truncating tables with foreign key dependencies.
        if ($reverse) {
            // Since the loading of tables took into account foreign key
            // dependencies we should be able to just reverse the order
            // of the database load. Right??
            $table_fixtures = array_reverse($table_fixtures, true);
        }

        // Iterate over the array unloading the tables
        foreach ($table_fixtures as $table => $fixture) {
            CIUnit::$fixture->unload($table);
            log_message('debug', 'Table fixture "' . $fixture . '" unloaded');
        }
    }

    /**
     * fixture wrapper, for arbitrary number of arguments
     */
    public function fixt()
    {
        $fixts = func_get_args();
        $this->load_fixt($fixts);
    }

    /**
     * loads a fixture from a yaml file
     */
    protected function load_fixt($fixts)
    {
        foreach ($fixts as $fixt) {
            $fixt_name = $fixt . '_fixt';

            if (file_exists(TESTSPATH . 'fixtures/' . $fixt . '_fixt.yml')) {
                $this->$fixt_name = CIUnit::$spyc->loadFile(TESTSPATH . 'fixtures/' . $fixt . '_fixt.yml');
            } else {
                die('The file ' . TESTSPATH . 'fixtures/' . $fixt . '_fixt.yml doesn\'t exist.');
            }
        }
    }
}
