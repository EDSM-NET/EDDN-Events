<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\System;

use         Alias\System\Power;

class PowerPlay
{
    static public function handle($systemId, $message)
    {
        $currentSystem  = \Component\System::getInstance($systemId);

        if($currentSystem->isValid() && $currentSystem->isHidden() === false)
        {
            if(array_key_exists('Powers', $message) && array_key_exists('PowerplayState', $message))
            {
                $systemsPowerplayModel  = new \Models_Systems_Powerplay;
                $currentPowerplay       = $systemsPowerplayModel->getByRefSystem($systemId);
                $updatePowerplay        = true;

                if(!is_null($currentPowerplay))
                {
                    foreach($currentPowerplay AS $powerplay)
                    {
                        if(strtotime($powerplay['dateUpdated']) >= strtotime($message['timestamp']))
                        {
                            $updatePowerplay = false;
                        }
                    }
                }

                if($updatePowerplay === true)
                {
                    $systemsPowerplayModel->deleteByRefSystem($systemId);
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\PowerPlay:</span>          Refreshing PowerPlay...');

                    foreach($message['Powers'] AS $power)
                    {
                        // Check if power is known in EDSM
                        $powerId        = \Alias\System\Power::getFromFd($power);

                        if(!is_null($powerId))
                        {
                            try
                            {
                                $systemsPowerplayModel->insert(array(
                                    'refSystem'     => $systemId,
                                    'refPower'      => $powerId,
                                    'state'         => $message['PowerplayState'],
                                    'dateUpdated'   => $message['timestamp'],
                                ));

                                \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\PowerPlay:</span>              - ' . $currentSystem->getName() . ' / ' . $power . ' / ' . $message['PowerplayState']);
                            }
                            catch(\Zend_Db_Exception $e)
                            {
                                //TODO: Handle expection
                            }
                        }
                        else
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\System\Power: ' . $power);
                        }
                    }
                }
            }
        }
    }
}