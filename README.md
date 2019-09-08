# yii2-sqlite3-full-support

Adds support for unsupported sqlite3 ALTER TABLE comands to Yii2 following the procedures stated at https://www.sqlite.org/lang_altertable.html#otheralter

Provides also a seamlessly translation of MySQL expressions into sqlite expressions.

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

