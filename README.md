# yii2-sqlite3-full-support

Adds support for unsupported sqlite3 ALTER TABLE comands to Yii2 following the procedures stated at https://www.sqlite.org/lang_altertable.html#otheralter

Provides also a seamlessly translation of MySQL expressions into sqlite expressions.

Manages DDL statements with attached databases

## Done
- Expression translating:
  - NOW() => 'CURRENT_TIMESTAMP'
- Drop column
- Alter column
- Add foreign key
- Rename column
- Add primary key
- Drop foreign key (works with migrate/refresh which passes index of foreign key instead of name)
- Drop Unique

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
	When using `safeUp` and `safeDown` in a migration that includes `Drop column`, `Alter column`, `Add foreign key`, `Rename column` and `Drop foreign key` the migration fails because the `pragma foreignkeys` only works outside transactions. You have to use `up` and `down` instead in your migration.

	The command migrate/fresh drops all foreign keys prior to dropping tables. As the migrate controller doesn't send the name of the foreign key, but the number of foreign key instead, you have to change this line of code:
```
diff --git a/db/sqlite/Schema.php b/db/sqlite/Schema.php
index c8898d817..2e30e823f 100644
--- a/db/sqlite/Schema.php
+++ b/db/sqlite/Schema.php
@@ -254,7 +254,7 @@ class Schema extends \yii\db\Schema implements ConstraintFinderInterface
{
$sql = 'PRAGMA foreign_key_list(' . $this->quoteSimpleTableName($table->name) . ')';
$keys = $this->db->createCommand($sql)->queryAll();
-        foreach ($keys as $key) {
+        foreach (array_reverse($keys) as $key) {
$id = (int) $key['id'];
if (!isset($table->foreignKeys[$id])) {
$table->foreignKeys[$id] = [$key['table'], $key['from'] => $key['to']];
```
