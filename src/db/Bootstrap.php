<?php
namespace santilin\db;

use yii\base\Application;
use yii\base\BootstrapInterface;

/**
 * Bootstrap class of the yii2-sqlite3-full-support extension.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * {@inheritdoc}
     */
    public function bootstrap($app)
    {
		\Yii::$classMap['yii\db\sqlite\QueryBuilder'] = "@santilin/db/sqlite/QueryBuilder.php";
	}
}
