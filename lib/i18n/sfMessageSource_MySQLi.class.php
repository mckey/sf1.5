<?php

/**
 * sfMessageSource_MySQLi class file.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the BSD License.
 *
 * Copyright(c) 2019 by Vlamimir Makarov. All rights reserved.
 *
 * To contact the author write to {@link mailto:mckey11@gmail.com Vlamimir Makarov}
 *
 * @author     Vlamimir Makarov <mckey11[at]gmail[dot]com>
 * @version    $Id$
 * @package    symfony
 * @subpackage i18n
 */

/**
 * sfMessageSource_MySQLi class.
 *
 * Retrieve the message translation from a MySQL database.
 *
 * See the MessageSource::factory() method to instantiate this class.
 *
 * MySQL schema:
 *
 * Catalogue:
 *   actAs:
 *     Timestampable: ~
 *     Signable: ~
 *   columns:
 *     name: {type: string(100), notnull: true, default: ''}
 *     source_lang: {type: string(100), notnull: true, default: ''}
 *     target_lang: {type: string(100), notnull: true, default: ''}
 *
 * TransUnit:
 *   actAs:
 *     Timestampable: ~
 *     Signable: ~
 *   columns:
 *     catalogue_id: { type: integer, notnull: true }
 *     msg: {type: string(255), notnull: true, default: ''}
 *     source: {type: clob, notnull: true, default: ''}
 *     target: {type: clob, notnull: true, default: ''}
 *     comments: {type: clob, notnull: true, default: ''}
 *     translated:  { type: boolean, notnull: true, default: 0 }
 *   relations:
 *     Catalogue: { class: Catalogue, onDelete: CASCADE, local: catalogue
 *
 * @author     Vlamimir Makarov <mckey11[at]gmail[dot]com>
 * @version    v1.0
 * @package    symfony
 * @subpackage i18n
 */
class sfMessageSource_MySQLi extends sfMessageSource_Database
{
  /**
   * The data source string, full DSN to the database.
   * @var string
   */
  protected $source;

  /**
   * Catalog name
   * @var string
   */
  protected $catalog;

  /**
   * The DSN array property, parsed by PEAR's DB DSN parser.
   * @var array
   */
  protected $dsn;

  /**
   * A resource link to the database
   * @var string
   */
  protected $db;

  /**
   * Constructor.
   * Creates a new message source using MySQLi.
   *
   * @param mixed $source MySQL data source, in PEAR's DB DSN format.
   * @param string $catalog catalog name.
   * @throws null
   * @see MessageSource::factory();
   */
  function __construct($source, $catalog = 'messages')
  {
    if (is_subclass_of($source, 'sfDatabase')) {
      $this->db = $source->getDoctrineConnection();
    } elseif (is_string($source)) {
      $this->source = (string)$source;
      $this->dsn = $this->parseDSN($this->source);
      $this->db = $this->connect();
    }

    $this->catalog = (string)$catalog;
  }

  /**
   * Destructor, closes the database connection.
   */
  function __destruct()
  {
    @mysqli_close($this->db);
  }

  /**
   * Connects to the MySQLi datasource
   *
   * @return mysqli MySQLi connection.
   * @throws sfException, connection and database errors.
   */
  protected function connect()
  {
    $dsninfo = $this->dsn;

    if (isset($dsninfo['protocol']) && $dsninfo['protocol'] == 'unix') {
      $dbhost = ':' . $dsninfo['socket'];
    } else {
      $dbhost = $dsninfo['hostspec'] ?: 'localhost';
      if (!empty($dsninfo['port'])) {
        $dbhost .= ':' . $dsninfo['port'];
      }
    }
    $user = $dsninfo['username'];
    $pw = $dsninfo['password'];

    if ($dbhost && $user && $pw) {
      $conn = new mysqli($dbhost, $user, $pw);
    } elseif ($dbhost && $user) {
      $conn = new mysqli($dbhost, $user);
    } elseif ($dbhost) {
      $conn = new mysqli($dbhost);
    } else {
      $conn = new mysqli();
    }

    if (mysqli_connect_errno()) {
      throw new sfException(sprintf('Error in connecting to %s.', mysqli_connect_errno()));
    }

    if ($dsninfo['database']) {
      if (!mysqli_select_db($conn, $dsninfo['database'])) {
        throw new sfException(sprintf('Error in connecting database, dsn: %s.', $dsninfo));
      }
    } else {
      throw new sfException('Please provide a database for message translation.');
    }

    return $conn;
  }

  /**
   * Gets the database connection.
   *
   * @return mysqli database connection.
   */
  public function connection()
  {
    return $this->db;
  }

  /**
   * Gets an array of messages for a particular catalogue and cultural variant.
   *
   * @param string $variant the catalogue name + variant
   * @return array translation messages.
   */
  public function &loadData($variant)
  {
    $statement =
      "SELECT t.id, t.source, t.target, t.comments
        FROM trans_unit t, catalogue c
        WHERE c.id =  t.catalogue_id
          AND c.name = '{$variant}'
        ORDER BY id ASC";

    $rs = $this->db->execute($statement);

    $result = [];

    while ($row = $rs->fetch(Doctrine_Core::FETCH_NUM)) {
      $source = $row[1];
      $result[$source][] = $row[2]; //target
      $result[$source][] = $row[0]; //id
      $result[$source][] = $row[3]; //comments
    }

    return $result;
  }

  /**
   * Gets the last modified unix-time for this particular catalogue+variant.
   * We need to query the database to get the date_modified.
   *
   * @param string $source catalogue+variant
   * @return int last modified in unix-time format.
   */
  protected function getLastModified($source)
  {
    $statement = $this->db->execute("SELECT updated_at FROM catalogue WHERE name = '{$source}'");
    $row = $statement->fetch(Doctrine_Core::FETCH_NUM);
    return !empty($row[0]) ? (int)$row[0] : 0;
  }

  /**
   * Checks if a particular catalogue+variant exists in the database.
   *
   * @param string $variant catalogue+variant
   * @return boolean true if the catalogue+variant is in the database, false otherwise.
   */
  public function isValidSource($variant)
  {
    $rs = $this->db->execute("SELECT id FROM catalogue WHERE name = '{$variant}'");
    if (!in_array($variant, ['messages', 'messages.en', 'site', 'site.en']) && $rs->rowCount() == 0) {
      $time = date('Y-m-d h:i:s');
      $statement = "INSERT INTO catalogue
        (name,source_lang,target_lang,created_at,created_by) VALUES
        ('{$variant}', 'en','{$this->culture}','{$time}', 1)";
      $rs = $this->db->execute($statement);
    }
    return $rs->rowCount() == 1;
  }

  /**
   * Retrieves catalogue details, array($cat_id, $variant, $count).
   *
   * @param string $catalogue catalogue
   * @return array catalogue details, array($cat_id, $variant, $count).
   */
  protected function getCatalogueDetails($catalogue = 'messages')
  {
    $variant = $catalogue . '.' . $this->culture;

    $name = $this->getSource($variant);

    $rs = $this->db->execute("SELECT id FROM catalogue WHERE name = '{$name}'");

    if ($rs->rowCount() != 1) {
      return [];
    }

    $catalogue = $rs->fetch(Doctrine_Core::FETCH_NUM);
    $cat_id = (int)$catalogue[0];

    $rs = $this->db->execute("SELECT id FROM trans_unit WHERE catalogue_id = {$cat_id}");
    $count = $rs->rowCount();

    return [$cat_id, $variant, $count];
  }

  /**
   * Updates the catalogue last modified time.
   *
   * @param string $cat_id catalogue id
   * @param string $variant culture
   * @return boolean true if updated, false otherwise.
   */
  protected function updateCatalogueTime($cat_id, $variant)
  {
    $time = date('Y-m-d h:i:s');

    $result = $this->db->execute("UPDATE catalogue SET updated_at = '{$time}' WHERE id = {$cat_id}");

    if ($this->cache) {
      $this->cache->remove($variant . ':' . $this->culture);
    }

    return $result;
  }

  /**
   * Saves the list of untranslated blocks to the translation source.
   * If the translation was not found, you should add those
   * strings to the translation source via the <b>append()</b> method.
   *
   * @param string $catalogue the catalogue to add to
   * @return boolean true if saved successfully, false otherwise.
   */
  function save($catalogue = 'messages')
  {
    $messages = $this->untranslated;

    if (count($messages) <= 0) {
      return false;
    }

    $details = $this->getCatalogueDetails($catalogue);

    if (count($details) == 3) {
      [$cat_id, $variant, $count] = $details;
    } else {
      return false;
    }

    if ($cat_id <= 0) {
      return false;
    }

    $inserted = 0;
    $formatter = new Doctrine_Formatter();
    $time = date('Y-m-d h:i:s');

    foreach ($messages as $message) {
      $count++;
      $inserted++;
      $message = $formatter->quote($message, 'string');
      $statement = "INSERT INTO trans_unit
        (catalogue_id,msg,source,created_at,created_by) VALUES
        ({$cat_id}, {$count}, {$message},'{$time}', 1)";
      $this->db->execute($statement);
    }

    if ($inserted > 0) {
      $this->updateCatalogueTime($cat_id, $variant);
    }

    return $inserted > 0;
  }

  /**
   * Adds the translation.
   *
   * @param string $source the source string.
   * @param string $target the new translation string.
   * @param string $comments
   * @param string $catalogue the catalogue of the translation.
   * @param int $user_id
   * @return boolean true if translation was added, false otherwise.
   */
  function add($source, $target, $comments = '', $catalogue = 'messages', $user_id = 1)
  {
    $details = $this->getCatalogueDetails($catalogue);

    if (count($details) == 3) {
      [$cat_id, $variant, $count] = $details;
    } else {
      return false;
    }

    if ($cat_id <= 0) {
      return false;
    }

    $time = date('Y-m-d h:i:s');

    $count++;

    $formatter = new Doctrine_Formatter();
    $source = $formatter->quote($source, 'string');
    $target = $formatter->quote($target, 'string');
    $comments = $formatter->quote($comments, 'string');
    $statement = "INSERT INTO trans_unit
        (catalogue_id,msg,source,target,comments,created_at,created_by) VALUES
        ({$cat_id},{$count},{$source},{$target},{$comments},'{$time}',{$user_id})";
    $result = $this->db->execute($statement);

    if(!empty($result)) {
      $this->updateCatalogueTime($cat_id, $variant);
    }

    return !empty($result);
  }

  /**
   * Deletes a particular message from the specified catalogue.
   *
   * @param string $message the source message to delete.
   * @param string $catalogue the catalogue to delete from.
   * @return boolean true if deleted, false otherwise.
   */
  function delete($message, $catalogue = 'messages')
  {
    $details = $this->getCatalogueDetails($catalogue);
    if (count($details) == 3) {
      [$cat_id, $variant, $count] = $details;
    } else {
      return false;
    }

    $statement = "DELETE FROM trans_unit WHERE catalogue_id = {$cat_id} AND source = '{$message}'";
    $deleted = false;

    $rs = $this->db->execute($statement);

    if ($rs->rowCount() == 1) {
      $deleted = $this->updateCatalogueTime($cat_id, $variant);
    }

    return $deleted;
  }

  /**
   * Updates the translation.
   *
   * @param string $text the source string.
   * @param string $target the new translation string.
   * @param string $comments comments
   * @param string $catalogue the catalogue of the translation.
   * @return boolean true if translation was updated, false otherwise.
   */
  function update($text, $target, $comments = '', $catalogue = 'messages')
  {
    $details = $this->getCatalogueDetails($catalogue);
    if (count($details) == 3) {
      [$cat_id, $variant, $count] = $details;
    } else {
      return false;
    }

    $formatter = new Doctrine_Formatter();

    $text = $formatter->quote($text, 'string');
    $target = $formatter->quote($target, 'string');
    $comments = $formatter->quote($comments, 'string');

    $time = date('Y-m-d h:i:s');

    $statement = "UPDATE trans_unit SET target = {$target}, comments = {$comments}, updated_at = '{$time}' WHERE catalogue_id = {$cat_id} AND source = {$text}";

    $updated = false;

    $rs = $this->db->execute($statement);

    if ($rs->rowCount() == 1) {
      $updated = $this->updateCatalogueTime($cat_id, $variant);
    }

    return $updated;
  }

  /**
   * Returns a list of catalogue as key and all it variants as value.
   *
   * @return array list of catalogues
   */
  function catalogues()
  {
    $statement = 'SELECT name FROM catalogue ORDER BY name';
    $rs = $this->db->execute($statement);
    $result = [];
    while ($row = $rs->fetch(Doctrine_Core::FETCH_NUM)) {
      $details = explode('.', $row[0]);
      if (!isset($details[1])) {
        $details[1] = null;
      }

      $result[] = $details;
    }

    return $result;
  }
}
