# yii2-sqlite3-full-support

Adds support for unsupported sqlite3 ALTER TABLE comands to Yii2 following the procedures stated at https://www.sqlite.org/lang_altertable.html#otheralter

Provides also a seamlessly translation of MySQL expressions into sqlite expressions.

Manages DDL statements with attached databases

## Done
- Drop column
- Alter column
- Add foreign key
- Add primary key
- Drop foreign key (works with migrate/refresh which passes index of foreign key instead of name)
- Drop Unique
	- Expression translating:
	- NOW() => 'CURRENT_TIMESTAMP'
	- UNIX_TIMESTAMP() => CAST(strftime('%s', 'now') AS INT)
	- CONCAT => ||

## Todo
- Drop Primary key
- Add Check
- Drop Check
- Add default value
- Drop default value
- Add comment on column
- Drop comment from column
- Add Comment on table
- Drop comment from table
- buildSubqueryInCondition

# Install

    require "santilin/yii2-sqlite3-full-support": "*"

# Usage

The extension works out of the box without any configuration.
The boostrap of this extension replaces the className of yii\db\sqlite\QueryBuilder to point to this custom QueryBuilder.

# Caveats
## Foreign key disabling
When using `safeUp` and `safeDown` in a migration that includes `Drop column`, `Alter column`, `Add foreign key`, `Rename column` and `Drop foreign key` the migration fails because the `pragma foreignkeys` only works outside transactions. You have to use `up` and `down` instead in your migration. A exception is thrown if foreign keys can not be disabled.

You can force the migration even if foreign keys can not be disabled defining the environment variable YII2_SQLITE3_NO_ENABLE_FOREIGN_CHECKS

```
YII2_SQLITE3_DISABLE_FOREIGN_CHECKS=1 ./yii migrate/fresh
```

## migrate/fresh
The command `migrate/fresh` drops all foreign keys prior to dropping tables. As sqlite doesn't returns the name of the foreign key but the number of foreign, when the first foreignk key is dropped, the second one becomes the first and so, when trying to delete the second one, it no longer exists. To fix this, you have to change this line of code:

```
diff --git a/console/controllers/MigrateController.php b/console/controllers/MigrateController.php
index 17b6a7638..6f553a22d 100644
--- a/console/controllers/MigrateController.php
+++ b/console/controllers/MigrateController.php
@@ -301,7 +301,7 @@ class MigrateController extends BaseMigrateController

         // First drop all foreign keys,
         foreach ($schemas as $schema) {
-            foreach ($schema->foreignKeys as $name => $foreignKey) {
+            foreach ( array_reverse($schema->foreignKeys, true) as $name => $foreignKey) {
                 $db->createCommand()->dropForeignKey($name, $schema->name)->execute();
                 $this->stdout("Foreign key $name dropped.\n");
             }

```
