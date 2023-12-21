<?php
namespace Gurucomkz\ExternalData\Examples;

use Gurucomkz\ExternalData\Model\ExternalDataObject;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\DB;

/**
 * ExternalMySQLDataObject
 *
 * This is an example implementation with a external MySQL database
 * It uses a switch in query_remote(), where it connects with a alternative DB conn,
 * execute a raw query and return to the default DB::conn()
 *
 * Make sure you set a $remote_database_config details
 *
 * @see RestDataObject
 */
class ExternalMySQLDataObject extends ExternalDataObject
{

    public static $table = 'ExternalMySQLDataObject';
    public static $insert_id = 0;

    static $db = [
        'Title' => 'Varchar(255)',
        'Name'  => 'Varchar(255)',
        'Email' => 'Varchar(255)'
    ];

    static $summary_fields = [
        'Title' => 'Title',
        'Name'  => 'Name',
        'Email' => 'Email'
    ];

    private static $singular_name = 'External MySQL DataObject';

    /**
     * remote mysql database connection
     **/
    static $remote_database_config = [
        "type" => 'MySQLDatabase',
        "server" => 'localhost',
        "username" => '',
        "password" => '',
        "database" => '',
        "path" => ''
    ];

    /**
     * Return to the default $databaseConfig
     * Add this after each remote table operations are executed
     **/
    public static function return_to_default_config()
    {
        global $databaseConfig;
        $dbClass = $databaseConfig['type'];
        $conn = new $dbClass($databaseConfig);
        DB::set_conn($conn);
    }

    /**
     * Connect to a secondary MySQL database
     **/
    public static function connect_remote()
    {
        $config = self::$remote_database_config;
        $dbClass = $config['type'];
        $conn = new $dbClass($config);
        DB::set_conn($conn);
    }

    /**
     * Connect to a secondary MySQL database, execute the query and set the database to the default connection
     **/
    public static function query_remote($query)
    {
        self::connect_remote();
        $res = DB::query($query);
        $table = Config::forClass(get_called_class())->get('table');
        self::$insert_id = DB::get_conn()->getGeneratedID($table);
        self::return_to_default_config();
        return $res;
    }

    public static function get()
    {
        $list = parent::get();
        $table = Config::forClass(get_called_class())->get('table');
        if ($res = self::query_remote("SELECT * FROM $table")) {
            foreach ($res as $item) {
                $list->push(new ExternalMySQLDataObject($item));
            }
        }
        return $list;
    }

    public static function get_by_id($id)
    {
        $table = Config::forClass(get_called_class())->get('table');
        if ($res = self::query_remote("SELECT * FROM $table WHERE ID =" . (int)$id)->record()) {
            return new ExternalMySQLDataObject($res);
        }
    }

    public static function delete_by_id($id)
    {
        $table = Config::forClass(get_called_class())->get('table');
        $res = self::query_remote("DELETE FROM $table WHERE ID =" . (int)$id);
    }

    public function write()
    {
        // remove values that are not in self::$db
        $writableData = Convert::raw2sql(array_intersect_key($this->record, $this->db()));
        if ($this->ID) {
            $updates = [];
            foreach ($writableData as $k => $v) {
                $updates[] = "{$k} = '{$v}'";
            }
            $query = "UPDATE RestDataObject SET " . implode(",", $updates) . " WHERE ID=" . $this->ID;
            self::query_remote($query);
        } else {
            $query = "INSERT INTO RestDataObject (" . implode(',', array_keys($writableData)) . ") VALUES ('" . implode("','", $writableData) . "')";
            self::query_remote($query);
            $this->record['ID'] = self::$insert_id;
            $this->getID();
        }
        return $this->ID;
    }

    function delete()
    {
        self::query_remote("DELETE FROM RestDataObject WHERE ID =" . (int)$this->getID());
        $this->flushCache();
        $this->ID = '';
    }
}
