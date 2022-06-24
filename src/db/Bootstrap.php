<?php
namespace santilin\db;

use Yii;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\db\Connection;

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
		if( isset(Yii::$app->db) ) {
			if( empty(getenv('YII2_SQLITE3_NO_ENABLE_FOREIGN_CHECKS')) 
				&& !(Yii::$app->params['diable_foreign_key_checks']??false) ) {
					Yii::$app->db->on(Connection::EVENT_AFTER_OPEN, function($e) {
						Yii::$app->db->createCommand()->checkIntegrity(true)->execute();
					});
				}
			}
		}
	}
}
