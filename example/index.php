<?php
// quick simple example of SimpleDb usage...
// data folder needs to be writable by web server
// Zend needs to be in library or default search path (like pear)

define('APP_PATH', dirname(__FILE__));
set_include_path(APP_PATH.'/../library'.PATH_SEPARATOR.get_include_path());
set_include_path(APP_PATH.'/models'.PATH_SEPARATOR.get_include_path());

require_once 'Zend/Loader/Autoloader.php';

$autoloader = Zend_Loader_Autoloader::getInstance();
$autoloader->setFallbackAutoloader(true);

require_once 'Product.php';
require_once 'User.php';

$zendDb = Zend_Db::factory('Pdo_Sqlite', array(
    'dbname'   => APP_PATH.'/data/test.db'
));

function setupTestDb($db)
{
	$create = array(
		'CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY, name TEXT, price REAL, on_sale INTEGER DEFAULT 0, user_id INTEGER, created_on TEXT);',
		'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, password TEXT);'
	);
	foreach($create as $sql)
	{
		$db->exec($sql);
	}
}

setupTestDb($zendDb);

// simple db takes a Zend_Db instance and either a writable path or Zend_Cache instance
$db = new SimpleDb($zendDb, APP_PATH.'/data');
$db->addClasses('Product', 'User'); // inform simpledb of our model classes (what about a dir scanner?)
// you can access tables without model classes, results will be SimpleDb_Item instances

$user = $db->users->new(array('username' => 'jim', 'email' => 'jim@example.com'));
print 'Saving '.$user.' to database...<br />';
$user->save();

for($i=1; $i < 5; $i++)
{
	$product = $db->products->new();
	$product->name = 'Test Product '.$i;
	$product->user_id = $user->id;
	$product->price = (float)($i.'.99');
	$product->on_sale = ($i % 2) ? 1 : 0;
	$product->save();
}

foreach($db->products as $product)
{
	print 'Product: '.$product.' user: '.$product->user->username.' - sale('.$product->on_sale.')<br />';
}

print '<strong>on_sale:</strong><br />';

// you can start a query, but chain changes to it later on, it will not execute until needed
$sale_products = $db->products->where('on_sale = ?', 1);
print 'Count: '.count($sale_products).'<br />';
foreach($sale_products->order('name asc') as $product)
{
	print 'Product: '.$product.' user: '.$product->user->username.' - sale('.$product->on_sale.')<br />';
	// delete for fun
	$product->delete();
}

print '<strong>After delete:</strong><br />';
foreach($db->products as $product)
{
	print 'Product: '.$product.' user: '.$product->user->username.' - sale('.$product->on_sale.')<br />';
}
