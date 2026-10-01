declare module "*.css";

declare module "@wordpress/block-editor" {
  import type { ComponentType, ReactNode } from "react";

  interface InnerBlocksComponent {
    Content: ComponentType;
  }

  interface UseBlockProps {
    (properties?: Record<string, unknown>): Record<string, unknown>;
    save(properties?: Record<string, unknown>): Record<string, unknown>;
  }

  export const InnerBlocks: InnerBlocksComponent;
  export const BlockList: ComponentType;
  export const BlockPreview: ComponentType<{ blocks: unknown[]; viewportWidth?: number }>;
  export const BlockTools: ComponentType<{ children?: ReactNode }>;
  export const BlockEditorProvider: ComponentType<{
    value: unknown[];
    onInput(value: unknown[]): void;
    onChange(value: unknown[]): void;
    children?: ReactNode;
  }>;
  export const BlockContextProvider: ComponentType<{ value: Record<string, unknown>; children?: ReactNode }>;
  export const InspectorControls: ComponentType<{ group?: string; children?: ReactNode }>;
  export function useBlockEditingMode(mode?: "default" | "contentOnly" | "disabled"): string;
  export const useBlockProps: UseBlockProps;
  export function useInnerBlocksProps(
    properties: Record<string, unknown>,
    options: {
      value?: unknown[];
      onInput?(value: import("./extension-slot/identity").EditorBlock[]): void;
      onChange?(value: import("./extension-slot/identity").EditorBlock[]): void;
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
  export function getBlockType(name: string): { title?: string; allowedBlocks?: string[]; parent?: string[] } | undefined;
  export function parse(content: string): unknown[];
  export function serialize(blocks: unknown[]): string;
  export function serializeRawBlock(block: unknown): string;
  export function registerBlockType(
    name: string,
    settings: Record<string, unknown>,
  ): unknown;
}

declare module "@wordpress/block-serialization-default-parser" {
  export function parse(content: string): unknown[];
}

declare module "@wordpress/api-fetch" {
  const apiFetch: <T>(options: { path: string }) => Promise<T>;
  export default apiFetch;
}

declare module "@wordpress/components" {
  import type { ComponentType, ReactNode } from "react";
  export const Button: ComponentType<import("react").ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "secondary" | "tertiary"; isDestructive?: boolean }>;
  export const PanelBody: ComponentType<{ title: string; initialOpen?: boolean; children?: ReactNode }>;
  export const Modal: ComponentType<{
    title: string;
    onRequestClose(): void;
    className?: string;
    children?: ReactNode;
  }>;
}

declare module "@wordpress/data" {
  export function useSelect<T>(
    mapSelect: (select: (store: string) => unknown) => T,
    dependencies: unknown[],
  ): T;
  export function useDispatch(store: string): unknown;
  export function useRegistry(): { select(store: string): unknown; dispatch(store: string): unknown };
}

declare module "@wordpress/element" {
  export { useEffect, useState, useRef } from "react";
  export { createPortal } from "react-dom";
}

declare module "@wordpress/i18n" {
  export function __(message: string, domain: string): string;
  export function sprintf(format: string, ...values: Array<string | number>): string;
}

declare module "@wordpress/hooks" {
  export function addFilter(hookName: string, namespace: string, callback: unknown): void;
}
