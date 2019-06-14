# yii2-sqlite3-full-support

Adds support for unsupported sqlite3 ALTER TABLE comands to Yii2 following the procedures stated at https://www.sqlite.org/lang_altertable.html#otheralter

## Done
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
- upsert

# Install

    require "santilin/yii2-sqlite3-full-support": "*"
    
# Usage

Due to the lack of container injection for Schema::QueryBuilder ( see https://github.com/yiisoft/yii2/issues/9740 ) to use the sqlite driver you have to change the controllerMap. Add this to your application main [entry script](https://www.yiiframework.com/doc/guide/2.0/en/structure-entry-scripts):

    Yii::$classMap['yii\db\sqlite\QueryBuilder'] = "@vendor/santilin/yii2-sqlite3-full-support/src/sqlite/QueryBuilder.php";


