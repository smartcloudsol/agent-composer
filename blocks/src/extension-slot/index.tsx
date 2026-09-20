import { type BlockAttribute, registerBlockType } from "@wordpress/blocks";
import metadata from "./block.json";
import Edit from "./edit";
import Save from "./save";

registerBlockType(metadata.name, {
  apiVersion: metadata.apiVersion,
  attributes: metadata.attributes as Record<string, BlockAttribute>,
  category: metadata.category,
  description: metadata.description,
  edit: Edit,
  icon: metadata.icon,
  save: Save,
  supports: metadata.supports,
  textdomain: metadata.textdomain,
  title: metadata.title,
});
