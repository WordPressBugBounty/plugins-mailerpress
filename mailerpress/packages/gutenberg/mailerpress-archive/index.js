import { registerBlockType } from "@wordpress/blocks";
import Edit from "./edit";
import metadata from "./block.json";
import { MailerPressIcon } from "../constants";

registerBlockType(metadata.name, {
  icon: MailerPressIcon,
  edit: Edit,
  save: () => null,
});
