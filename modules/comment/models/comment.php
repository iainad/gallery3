<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2009 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Comment_Model extends ORM {
  var $rules = array(
    "text" => array("rules" => array("required")),
    "state" => array("rules" => array("Comment_Model::valid_state"))
  );

  function item() {
    return ORM::factory("item", $this->item_id);
  }

  function author() {
    return identity::lookup_user($this->author_id);
  }

  function author_name() {
    $author = $this->author();
    if ($author->guest) {
      return $this->guest_name;
    } else {
      return $author->display_name();
    }
  }

  function author_email() {
    $author = $this->author();
    if ($author->guest) {
      return $this->guest_email;
    } else {
      return $author->email;
    }
  }

  function author_url() {
    $author = $this->author();
    if ($author->guest) {
      return $this->guest_url;
    } else {
      return $author->url;
    }
  }

  /**
   * Add some custom per-instance rules.
   */
  public function validate($array=null) {
    // validate() is recursive, only modify the rules on the outermost call.
    if (!$array) {
      $this->rules["item_id"]["callbacks"] = array(array($this, "valid_item"));
      $this->rules["guest_name"]["callbacks"] = array(array($this, "valid_author"));
    }

    parent::validate($array);
  }

  /**
   * @see ORM::save()
   */
  public function save() {
    $this->updated = time();
    if (!$this->loaded()) {
      // New comment
      $this->created = $this->updated;
      if (empty($this->state)) {
        $this->state = "published";
      }

      // These values are useful for spam fighting, so save them with the comment.  It's painful to
      // check each one to see if it already exists before setting it, so just use server_http_host
      // as a semaphore for now (we use that in g2_import.php)
      if (empty($this->server_http_host)) {
        $input = Input::instance();
        $this->server_http_accept = substr($input->server("HTTP_ACCEPT"), 0, 128);
        $this->server_http_accept_charset = substr($input->server("HTTP_ACCEPT_CHARSET"), 0, 64);
        $this->server_http_accept_encoding = substr($input->server("HTTP_ACCEPT_ENCODING"), 0, 64);
        $this->server_http_accept_language = substr($input->server("HTTP_ACCEPT_LANGUAGE"), 0, 64);
        $this->server_http_connection = substr($input->server("HTTP_CONNECTION"), 0, 64);
        $this->server_http_host = substr($input->server("HTTP_HOST"), 0, 64);
        $this->server_http_referer = substr($input->server("HTTP_REFERER"), 0, 255);
        $this->server_http_user_agent = substr($input->server("HTTP_USER_AGENT"), 0, 128);
        $this->server_query_string = substr($input->server("QUERY_STRING"), 0, 64);
        $this->server_remote_addr = substr($input->server("REMOTE_ADDR"), 0, 32);
        $this->server_remote_host = substr($input->server("REMOTE_HOST"), 0, 64);
        $this->server_remote_port = substr($input->server("REMOTE_PORT"), 0, 16);
      }
      $visible_change = $this->original()->state == "published" || $this->state == "published";
      parent::save();
      module::event("comment_created", $this);
    } else {
      // Updated comment
      $visible_change = $this->original()->state == "published" || $this->state == "published";
      $original = clone $this->original();
      parent::save();
      module::event("comment_updated", $original, $this);
    }

    // We only notify on the related items if we're making a visible change.
    if ($visible_change) {
      module::event("item_related_update", $this->item());
    }

    return $this;
  }

  /**
   * Add a set of restrictions to any following queries to restrict access only to items
   * viewable by the active user.
   * @chainable
   */
  public function viewable() {
    $this->join("items", "items.id", "comments.item_id");
    return item::viewable($this);
  }

  /**
   * Make sure we have an appropriate author id set, or a guest name.
   */
  public function valid_author(Validation $v, $field) {
    if ($this->author_id == identity::guest()->id && empty($this->guest_name)) {
      $v->add_error("guest_name", "required");
    }
  }

  /**
   * Make sure we have a valid associated item id.
   */
  public function valid_item(Validation $v, $field) {
    if (db::build()
        ->from("items")
        ->where("id", "=", $this->item_id)
        ->count_records() != 1) {
      $v->add_error("item_id", "invalid");
    }
  }

  /**
   * Make sure that the state is legal.
   */
  static function valid_state($value) {
    return in_array($value, array("published", "unpublished", "spam", "deleted"));
  }
}
