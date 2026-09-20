declare module "*.css";

declare module "@wordpress/block-editor" {
  import type { ComponentType } from "react";

  interface InnerBlocksComponent {
    Content: ComponentType;
  }

  interface UseBlockProps {
    (properties?: Record<string, unknown>): Record<string, unknown>;
    save(properties?: Record<string, unknown>): Record<string, unknown>;
  }

  export const InnerBlocks: InnerBlocksComponent;
  export const useBlockProps: UseBlockProps;
  export function useInnerBlocksProps(
    properties: Record<string, unknown>,
    options: {
      allowedBlocks?: string[];
      renderAppender?: ComponentType | (() => null);
      templateLock?: false | "all" | "insert" | "contentOnly";
    },
  ): Record<string, unknown>;
}

declare module "@wordpress/blocks" {
  export type BlockAttribute = Record<string, unknown>;
  export function createBlock(
    name: string,
    attributes?: Record<string, unknown>,
    innerBlocks?: unknown[],
  ): unknown;
  export function getBlockType(name: string): { title?: string } | undefined;
  export function registerBlockType(
    name: string,
    settings: Record<string, unknown>,
  ): unknown;
}

declare module "@wordpress/data" {
  export function useSelect<T>(
    mapSelect: (select: (store: string) => unknown) => T,
    dependencies: unknown[],
  ): T;
  export function useDispatch(store: string): unknown;
}

declare module "@wordpress/element" {
  export { useEffect, useState } from "react";
}

declare module "@wordpress/i18n" {
  export function __(message: string, domain: string): string;
  export function sprintf(format: string, ...values: Array<string | number>): string;
}
