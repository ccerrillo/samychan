<?php

namespace MM\SamyEditorBundle\Scm\Packer;

use MM\SamyEditorBundle\Entity;
use Symfony\Component\Yaml;
use MM\SamyEditorBundle\Scm\Sqlite3Database;

class Sqlite3Packer extends AbstractPacker {

    /**
     * assamble FileData
     *
     * @param Entity\ScmFile $scmFile
     * @return string
     */
    protected function assambleFileData(Entity\ScmFile $scmFile)
    {
        $scmChannels = $this->getDoctrine()->getRepository('MM\SamyEditorBundle\Entity\ScmChannel')->findBy(
            array('scmFile' => $scmFile),
            array('scm_channel_id' => 'ASC')
        );

        // if we dont have any channels, return the original binary data
        if (count($scmChannels) == 0) {
            return stream_get_contents($scmFile->getData());
        }

        // load Config
        $fileConfig = $this->getConfiguration()->getConfigBySeries($scmFile->getScmPackage()->getSeries());
        $fileConfig = $fileConfig[$scmFile->getFilename()];

        // open sqlite-db
        $db = new Sqlite3Database(stream_get_contents($scmFile->getData()));

        foreach ($scmChannels as $scmChannel) {
            $this->updateChannelData($scmChannel, $fileConfig, $db->getPdo());
        }

        return $db->getBinary();
    }

    /**
     * Sqlite3 Data des Channels aktualisieren
     *
     * @param ScmChannel $scmChannel
     * @param array $fileConfig
     * @param \PDO $pdo
     */
    protected function updateChannelData(Entity\ScmChannel $scmChannel, array $fileConfig, \PDO $pdo)
    {
        // iterate over fields and write the new value of each into the binaryString
        $values = [];
        foreach ($fileConfig['fields'] as $fieldName => $fieldConfig) {

            // update only editable fields
            if (!isset($fieldConfig['saveable']) || $fieldConfig['saveable'] == false) {
                continue;
            }

            // custom save-handler? skip this field...
            if (isset($fieldConfig['savehandler'])) {
                $saveHandler = new $fieldConfig['savehandler']();
                $saveHandler->save($fieldName, $fieldConfig, $scmChannel, $pdo);
                continue;
            }

            // get value from entity
            $fieldValue = $scmChannel->{'get' . ucfirst($fieldName)}();

            // load datatype and cast the value to binary
            $dataType = new $fieldConfig['type'];
            $binaryFieldValue = $dataType->toBinary($fieldValue);

            $values[':' . $fieldName] = $binaryFieldValue;
        }

        // update channel
        $sth = $pdo->prepare($fileConfig['updateSqlQuery']);

        foreach ($values as $column => $value) {

            $sth->bindParam($column, $value, $column == ':name' ? \PDO::PARAM_LOB : null);
        }

        $sth->execute();
    }
}