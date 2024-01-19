<?php
namespace Gurucomkz\ExternalData\Examples;

use Gurucomkz\ExternalData\Model\ExternalDataObject;
use MongoDB\Client;
use SilverStripe\Core\Config\Config;
use MongoDB\BSON\ObjectId;

// /**
//  * MongoDataObject
//  *
//  * This is an example implementation with a MongoDB as the external datasource
//  * You need the PHP MongoDB extension loaded to run this example
//  *
//  * http://www.php.net/manual/en/book.mongo.php
//  */
// abstract class MongoDataObject extends ExternalDataObject
// {

//     private static $table = '';

//     private static $db = [
//         'Title' => 'Varchar(255)',
//         'Name'  => 'Varchar(255)',
//         'Email' => 'Varchar(255)'
//     ];

//     private static $summary_fields = [
//         'Title' => 'Title',
//         'Name'  => 'Name',
//         'Email' => 'Email'
//     ];

//     /**
//      * Dummy collection
//      * WIll be created if it does not exists
//      */
//     static function collection()
//     {
//         $m = new Client();
//         $db = 'externaldata';
//         return $m->selectCollection($db, Config::forClass(get_called_class())->get('table'));
//     }

//     /**
//      * MongoDB identifiers can be objects
//      * Make sure we have a $this->ID to work witch
//      */
//     public function getID()
//     {
//         $id = isset($this->record['ID']) ? $this->record['ID'] : '';
//         $id = isset($this->record['_id']) ? $this->record['_id'] : $id;

//         if (is_object($id)) {
//             $key = '$id';
//             return $id->$key;
//         }
//         $this->ID = $id;
//         return $id;
//     }

//     public static function get_by_id($id)
//     {
//         $document = self::collection()->findOne(['_id' => new ObjectId($id)]);
//         return new MongoDataObject($document);
//     }

//     public function write()
//     {
//         // remove values that are not in self::$db && selff:$fixed_fields
//         $writableData = array_intersect_key($this->record, $this->db());
//         $collection = self::collection();
//         if ($this->ID) {
//             $res = $collection->updateOne(['_id' => new ObjectId($this->ID)], $writableData);
//         } else {
//             $res = $collection->insertOne($writableData);
//             $this->record['ID'] = $writableData["_id"];
//             $this->getID();
//         }
//         return $this->ID;
//     }

//     function delete()
//     {
//         $res = self::collection()->deleteOne(['_id' => new ObjectId($this->getID())]);
//         $this->flushCache();
//         $this->ID = '';
//     }

//     public static function delete_by_id($id)
//     {
//         self::collection()->deleteOne(['_id' => new ObjectId($id)]);
//     }
// }
