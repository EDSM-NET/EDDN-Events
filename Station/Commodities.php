<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\Station;

use         Alias\Station\Commodity\Type as CommodityType;

class Commodities
{
    static public function handle($systemId, $stationId, $message)
    {
        $currentSystem  = \EDSM_System::getInstance($systemId);
        $currentStation = \EDSM_System_Station::getInstance($stationId);
        
        if($currentSystem->isValid() && $currentSystem->isHidden() === false && $currentStation->isValid() && $currentSystem->getId() == $currentStation->getSystem()->getId())
        {
            if(array_key_exists('commodities', $message) && array_key_exists('timestamp', $message))
            {
                $message['timestamp']       = str_replace(array('T', 'Z'), array(' ', ''), $message['timestamp']);
                
                // Compare last update with message timestamp
                if(strtotime($currentStation->getMarketLastUpdate()) < strtotime($message['timestamp']))
                {
                    $tweetDeletedCommodities    = array();
                    $tweetAddedCommodities      = array();
                    $lastStationMarketUpdate    = null;
                    
                    $stationsModel              = new \Models_Stations;
                    $stationsCommoditiesModel   = new \Models_Stations_Commodities;
                    
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Commodities:</span>       ' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') updated market.');
                    
                    $newCommodities = array();
                    $nbUpdates      = 0;
                    
                    // Create list of commodities with ID
                    foreach($message['commodities'] AS $commodity)
                    {
                        // "legality": "I"
                        if(array_key_exists('legality', $commodity) && $commodity['legality'] == 'I')
                        {
                            continue;
                        }
                        
                        if($commodity['name'] != 'Drones')
                        {
                            $commodity['name']  = str_replace('ı', 'i', $commodity['name']);
                            $commodityId        = CommodityType::getFromFd($commodity['name']);
                            
                            if(is_null($commodityId))
                            {
                                \EDSM_Api_Logger_Alias::log('Alias\Station\Commodity\Type #' . $currentStation->getId() . ':' . $commodity['name']);
                            }
                            else
                            {
                                $newCommodities[$commodityId] = $commodity;
                            }
                        }
                    }
                    
                    $oldCommodities = $stationsCommoditiesModel->getByRefStation($currentStation->getId());
                    
                    // Delete commodities not in the station anymore
                    if(!is_null($oldCommodities) && count($oldCommodities) > 0)
                    {
                        foreach($oldCommodities AS $oldCommodity)
                        {
                            // Update (Only if previous prices are older than 24h or if something is different)
                            if(array_key_exists($oldCommodity['refCommodity'], $newCommodities))
                            {
                                $updateArray = array();
                                
                                if($oldCommodity['buyPrice'] != $newCommodities[$oldCommodity['refCommodity']]['buyPrice'])
                                {
                                    $updateArray['buyPrice']        = $newCommodities[$oldCommodity['refCommodity']]['buyPrice'];
                                    $updateArray['oldBuyPrice']     = $oldCommodity['buyPrice'];
                                }
                                
                                if($oldCommodity['stock'] != $newCommodities[$oldCommodity['refCommodity']]['stock'])
                                {
                                    $updateArray['stock']           = $newCommodities[$oldCommodity['refCommodity']]['stock'];
                                    $updateArray['oldStock']        = $oldCommodity['stock'];
                                }
                                
                                if(array_key_exists('stockBracket', $newCommodities[$oldCommodity['refCommodity']]) && !empty($newCommodities[$oldCommodity['refCommodity']]['stockBracket']))
                                {
                                    if($oldCommodity['stockBracket'] != $newCommodities[$oldCommodity['refCommodity']]['stockBracket'])
                                    {
                                        $updateArray['stockBracket']   = $newCommodities[$oldCommodity['refCommodity']]['stockBracket'];
                                    }
                                }
                                elseif($oldCommodity['stockBracket'] > 0)
                                {
                                    $updateArray['stockBracket'] = 0;
                                }
                                                                
                                if($oldCommodity['sellPrice'] != $newCommodities[$oldCommodity['refCommodity']]['sellPrice'])
                                {
                                    $updateArray['sellPrice']       = $newCommodities[$oldCommodity['refCommodity']]['sellPrice'];
                                    $updateArray['oldSellPrice']    = $oldCommodity['sellPrice'];
                                }
                                
                                if($oldCommodity['demand'] != $newCommodities[$oldCommodity['refCommodity']]['demand'])
                                {
                                    $updateArray['demand']          = $newCommodities[$oldCommodity['refCommodity']]['demand'];
                                    $updateArray['oldDemand']       = $oldCommodity['demand'];
                                }
                                
                                if(array_key_exists('demandBracket', $newCommodities[$oldCommodity['refCommodity']]) && !empty($newCommodities[$oldCommodity['refCommodity']]['demandBracket']))
                                {
                                    if($oldCommodity['demandBracket'] != $newCommodities[$oldCommodity['refCommodity']]['demandBracket'])
                                    {
                                        $updateArray['demandBracket']   = $newCommodities[$oldCommodity['refCommodity']]['demandBracket'];
                                    }
                                }
                                elseif($oldCommodity['demandBracket'] > 0)
                                {
                                    $updateArray['demandBracket'] = 0;
                                }
                                
                                if(count($updateArray) > 0)
                                {
                                    $stationsCommoditiesModel->updateById($oldCommodity['id'], $updateArray);
                                    
                                    unset($updateArray['stockBracket'], $updateArray['demandBracket']);
                                    
                                    if(count($updateArray) > 0)
                                    {
                                        $nbUpdates++;
                                    }
                                }
                                
                                unset($newCommodities[$oldCommodity['refCommodity']]);
                            }
                            else
                            {
                                \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Commodities:</span>           - Delete ' . CommodityType::get($oldCommodity['refCommodity']));
                                
                                $stationsCommoditiesModel->deleteById($oldCommodity['id']);
                                $tweetDeletedCommodities[] = CommodityType::get($oldCommodity['refCommodity']);
                            }
                        }
                    }
                    
                    if($nbUpdates > 0)
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Commodities:</span>           - Updated ' . \Zend_Locale_Format::toNumber($nbUpdates) . ' commodities');
                    }
                    
                    // Add new commodities
                    foreach($newCommodities AS $commodityId => $commodity)
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Commodities:</span>           - Add ' . CommodityType::get($commodityId));
                        
                        $stationsCommoditiesModel->insert(array(
                            'refStation'        => $currentStation->getId(),
                            'refCommodity'      => $commodityId,
                            
                            'buyPrice'          => $commodity['buyPrice'],
                            'stock'             => $commodity['stock'],
                            'stockBracket'      => ( (!empty($commodity['stockBracket'])) ? $commodity['stockBracket'] : 0 ),
                            
                            'sellPrice'         => $commodity['sellPrice'],
                            'demand'            => $commodity['demand'],
                            'demandBracket'     => ( (!empty($commodity['demandBracket'])) ? $commodity['demandBracket'] : 0 ),
                        ));
                        
                        if(!is_null($oldCommodities) && count($oldCommodities) > 0)
                        {
                            $tweetAddedCommodities[] = CommodityType::get($commodityId);
                        }
                        
                        // Find alerts
                        $usersAlertsModel   = new \Models_Users_Alerts;
                        $alerts             = $usersAlertsModel->getAlerts(
                            $currentStation->getId(),
                            1,
                            $commodityId
                        );
                        
                        foreach($alerts AS $alert)
                        {
                            $user = \EDSM_User::getInstance($alert['refUser']);
                            
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
                                        'objectToFind'  => CommodityType::get($commodityId),
                                    ));
                                    
                                    $mail->addTo($user->getEmail());      
                                    
                                    $mail->setSubject($mail->getView()->translate('EMAIL\%1$s is in stock!' , CommodityType::get($commodityId)));
                                    $mail->send();
                                } 
                                catch(\Zend_Exception $e)
                                {
                                    // Do nothing, too bad the user will not see our alert email :)
                                }
                            }
                            
                            $usersAlertsModel->deleteById($alert['id']);
                            
                            \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Commodities:</span>           - Send alert to ' . $user->getCMDR());
                        }
                    }
                    
                    // Update prohibited list if market was updated
                    if(array_key_exists('prohibited', $message))
                    {
                        $prohibited = array();
                        
                        foreach($message['prohibited'] AS $commodity)
                        {
                            $commodityId = CommodityType::getFromFd($commodity);
                            
                            if(is_null($commodityId))
                            {
                                \EDSM_Api_Logger_Alias::log('Alias\Station\Commodity\Type #' . $currentStation->getId() . ':' . $commodity);
                            }
                            else
                            {
                                $prohibited[] = $commodityId;
                            }
                        }
                        
                        /*
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Commodities:</span>           - Prohibited: ' . implode(', ', array_map(function($commodityId) {
                            return CommodityType::get($commodityId);
                        }, $prohibited)));
                        */
                        
                        $stationsModel->updateById($currentStation->getId(), array('prohibited' => \Zend_Json::encode($prohibited), 'marketUpdateTime' => $message['timestamp']));
                    }
                    else
                    {
                        // Update update date into station
                        $stationsModel->updateById($currentStation->getId(), array('marketUpdateTime' => $message['timestamp']));
                    }
                    
                    // Tweet deleted commodities
                    if(count($tweetDeletedCommodities) > 0)
                    {
                        try
                        {
                            sort($tweetDeletedCommodities);
                            \EDSM_Api_Tweet::status(
                                  '"' . implode('", "', $tweetDeletedCommodities) . '" '
                                . ( (count($tweetDeletedCommodities) > 1) ? 'are' : 'is') 
                                . ' no longer in stock at ' 
                                . '"' . $currentSystem->getName() . ' / ' . $currentStation->getName() . '"'
                                //. ' #EliteDangerous'
                                . ' https://www.edsm.net/en/system/stations/id/' . $currentSystem->getId() . '/name//details/idS/' . $currentStation->getId() . '/nameS/' . urlencode($currentStation->getName()),
                                true
                            );
                        }
                        catch(\Zend_Exception $e)
                        {
                            \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Commodities:</span>           - ' . $e->getMessage());
                        }
                    }
                        
                    // Tweet added commodities
                    if(count($tweetAddedCommodities) > 0)
                    {
                        try
                        {
                            sort($tweetAddedCommodities);
                            \EDSM_Api_Tweet::status(
                                  '"' . implode('", "', $tweetAddedCommodities) . '" '
                                . ( (count($tweetAddedCommodities) > 1) ? 'are' : 'is') 
                                . ' back in stock at ' 
                                . '"' . $currentSystem->getName() . ' / ' . $currentStation->getName() . '"'
                                //. ' #EliteDangerous'
                                . ' https://www.edsm.net/en/system/stations/id/' . $currentSystem->getId() . '/name//details/idS/' . $currentStation->getId() . '/nameS/' . urlencode($currentStation->getName()),
                                true
                            );
                        }
                        catch(\Zend_Exception $e)
                        {
                            \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Commodities:</span>           - ' . $e->getMessage());
                        }
                    }
                }
                else
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Commodities:</span>       <span class="text-danger">' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') market too old (' . $message['timestamp'] . ').</span>');
                }
            }
        }
    }
}