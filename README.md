CacheSQL
========

CacheSQL class

### Usage:

```PHP
$cacheSQL = new CacheSQL('localhost', 'root', '', 'database_name', 'memcache');

// 10 is the life of how long this query should cache for
print_r($cacheSQL->query('select * from table1', 10));

print_r($cacheSQL->prepare('select :cols from :table'), array(':cols' => '*', ':table' => 'table1', 30));

//true in the last parameter forces the query to be re-run on the db
print_r($cacheSQL->prepare('select ? from ?'), array('name', 'users', 25, true));
```

The code is heavily phpdoc'ed so look through the code to understand the code, your IDE should fill in the rest