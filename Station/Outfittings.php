<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\Station;

use         Alias\Station\Outfitting\Type as OutfittingType;

class Outfittings
{
    static public function handle($systemId, $stationId, $message)
    {
        $currentSystem  = \Component\System::getInstance($systemId);
        $currentStation = \EDSM_System_Station::getInstance($stationId);

        if($currentSystem->isValid() && $currentSystem->isHidden() === false && $currentStation->isValid() && $currentSystem->getId() == $currentStation->getSystem()->getId())
        {
            if(array_key_exists('modules', $message) && array_key_exists('timestamp', $message))
            {
                $message['timestamp']       = str_replace(array('T', 'Z'), array(' ', ''), $message['timestamp']);

                // Compare last update with message timestamp
                if(strtotime($currentStation->getOutfittingLastUpdate()) < strtotime($message['timestamp']))
                {
                    $stationsModel              = new \Models_Stations;
                    $stationsOutfittingsModel   = new \Models_Stations_Outfittings;

                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Outfittings:</span>       ' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') updated outfittings.');

                    $newOutfittings = array();
                    $nbUpdates      = 0;

                    // Create list of outfittings with ID
                    foreach($message['modules'] AS $outfitting)
                    {
                        $outfittingId = OutfittingType::getFromFd($outfitting);

                        if(is_null($outfittingId))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Station\Outfitting\Type:' . $outfitting);
                        }
                        else
                        {
                            $newOutfittings[] = $outfittingId;
                        }
                    }

                    $oldOutfittings = $stationsOutfittingsModel->getByRefStation($currentStation->getId());

                    // Delete outfittings not in the station anymore
                    if(!is_null($oldOutfittings) && count($oldOutfittings) > 0)
                    {
                        foreach($oldOutfittings AS $oldOutfitting)
                        {
                            // Update
                            if(in_array($oldOutfitting['refOutfitting'], $newOutfittings))
                            {
                                $nbUpdates++;

                                unset($newOutfittings[array_search($oldOutfitting['refOutfitting'], $newOutfittings)]);
                            }
                            else
                            {
                                \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Outfittings:</span>           - Delete ' . OutfittingType::get($oldOutfitting['refOutfitting']));

                                $stationsOutfittingsModel->deleteById($oldOutfitting['id']);
                            }
                        }
                    }

                    if($nbUpdates > 0)
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Outfittings:</span>           - Updated ' . \Zend_Locale_Format::toNumber($nbUpdates) . ' outfittings');
                    }

                    // Add new outfittings
                    foreach($newOutfittings AS $newOutfitting)
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Outfittings:</span>           - Add ' . OutfittingType::get($newOutfitting));

                        $stationsOutfittingsModel->insert(array(
                            'refStation'    => $currentStation->getId(),
                            'refOutfitting' => $newOutfitting
                        ));

                        // Find alerts
                        $usersAlertsModel   = new \Models_Users_Alerts;
                        $alerts             = $usersAlertsModel->getAlerts(
                            $currentStation->getId(),
                            3,
                            $newOutfitting
                        );

                        foreach($alerts AS $alert)
                        {
                            $user = \Component\User::getInstance($alert['refUser']);

                            if($user->isValid())
                            {
                                try
                                {
                                    $mail = new \EDSM_Mail();
                                    $mail->setTemplate('alert.phtml');
                                    $mail->setLanguage($user->getLocale());
                                    $mail->setVariables(array(
                                        'commander'     => $user->getCMDR(),
                                        'system'        => $currentSystem,
                                        'station'       => $currentStation,
                                        'objectToFind'  => OutfittingType::get($newOutfitting),
                                    ));

                                    $mail->addTo($user->getEmail());

                                    $mail->setSubject($mail->getView()->translate('EMAIL\%1$s is in stock!' , OutfittingType::get($newOutfitting)));
                                    $mail->send();
                                }
                                catch(\Zend_Exception $e)
                                {
                                    // Do nothing, too bad the user will not see our alert email :)
                                }
                            }

                            $usersAlertsModel->deleteById($alert['id']);

                            \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Outfittings:</span>           - Send alert to ' . $user->getCMDR());
                        }
                    }

                    // Update update date into station
                    $stationsModel->updateById($currentStation->getId(), array('outfittingUpdateTime' => $message['timestamp']));
                }
                else
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Outfittings:</span>       <span class="text-danger">' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') outfittings too old (' . $message['timestamp'] . ').</span>');
                }
            }
        }
    }
}