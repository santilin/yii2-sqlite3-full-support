# yii2-sqlite3-full-support

Adds support for unsupported sqlite3 ALTER TABLE comands to Yii2 following the procedures stated at https://www.sqlite.org/lang_altertable.html#otheralter

Provides also a custom santilin\db\SqlExpression that translates MySQL expressions into sqlite.

## Done
- Expression translating:
  - NOW() => 'CURRENT_TIMESTAMP'
- Drop column
- Alter column
- Add foreign key
- Rename column
- Add primary key

## Not done yet but ignored (doesn't throw)
- Drop foreign key
- Drop Primary key
- Drop Unique
- Drop Check
- Drop default value
- Drop comment from column
- Drop comment from table

## Doing

## Todo
- Add Unique
- Add Check
- Add default value
- Add comment on column
- Add Comment on table
- buildSubqueryInCondition

# Install

    require "santilin/yii2-sqlite3-full-support": "*"

# Usage

	The extension works out of the box replacing the className for yii\db\sqlite\QueryBuilder to point to this custom QueryBuilder.

