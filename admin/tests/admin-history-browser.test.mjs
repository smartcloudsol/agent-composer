import assert from "node:assert/strict";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import test from "node:test";
const requirePublisher = createRequire(new URL("../../../../static-publisher/exporter/package.json", import.meta.url));
const { build } = requirePublisher("esbuild");
const { chromium } = requirePublisher("playwright");
const root = fileURLToPath(new URL("../../../..", import.meta.url));
const paths = {
  composer: `${root}/smartcloud-agent-ready-product-family/agent-composer/admin`,
  consent: `${root}/wpsuite-private-plugins/consent/admin`,
  relations: `${root}/wpsuite-private-plugins/content-relations/admin`,
};
const browser = await chromium.launch({ headless: true, args: ["--disable-gpu", "--disable-software-rasterizer"] });
const mocks = {
  "@wordpress/i18n": "export const __ = s => s; export const sprintf = (s,...args) => args.reduce((text,value)=>text.replace(/%[sd]/,String(value)),s);",
  "./api": `export const loadComposerStatus=async()=>({version:'fixture',active_config_set:null,mcp_endpoint:'fixture'});
    export const listConfigSets=async()=>({items:[],active:null});
    export const getConfigSet=async()=>null;
    export const exportConfigBackup=async()=>({}); export const importConfigPackage=async()=>({});
    export const loadAudit=async()=>({items:[]}); export const loadDiscovery=async()=>({}); export const runDiscovery=async()=>({});`,
  "./features": `export const ConfigSetsPanel = props => <button onClick={()=>props.setEntityChangesPending(true)}>Stage fixture edit</button>;
    export const ConfigurationBlueprintsPanel=()=> <div>Blueprint fixture</div>;
    export const ContentProposalsPanel=()=> <div>Proposal fixture</div>; export const McpAccessPanel=()=> <div>MCP fixture</div>;`,
  "./Onboarding": "export default ()=>null;",
  "./DocSidebar": "export default ()=>null;",
};
async function fixture(kind, search) {
  const isComposer = kind === "composer";
  const mount = isComposer ? "root" : kind === "consent" ? "smartcloud-consent-admin" : "smartcloud-content-relations-admin";
  const bundle = await build({
    absWorkingDir: paths[kind],
    stdin: {
      contents: isComposer
        ? `import '@mantine/core/styles.css';import React from 'react';import {createRoot} from 'react-dom/client';import {MantineProvider} from '@mantine/core';import {App} from './src/App';createRoot(document.getElementById('root')).render(<MantineProvider><App/></MantineProvider>);`
        : "import '@mantine/core/styles.css';import './src/index.tsx';",
      resolveDir: paths[kind], loader: "tsx",
    }, bundle: true, write: false, outdir: "/tmp/wpsuite-admin-history-other-browser", jsx: "automatic",
    alias: Object.fromEntries(["react", "react-dom", "@mantine/core", "@mantine/hooks", "@mantine/notifications", "@mantine/store"].map(name => [name, `${root}/smartcloud-agent-ready-product-family/node_modules/${name}`])),
    define: { "process.env.NODE_ENV": '"test"' },
    plugins: [{name:"services",setup(plugin){
      plugin.onResolve({filter:/.*/},({path})=> Object.hasOwn(mocks,path) && (isComposer || ["@wordpress/i18n", "./DocSidebar"].includes(path)) ? {path,namespace:"fixture"} : undefined);
      plugin.onLoad({filter:/.*/,namespace:"fixture"},({path})=>({contents:mocks[path],loader:"tsx",resolveDir:paths[kind]}));
    }}],
  });
  const page = await browser.newPage({viewport:{width:1440,height:1000}});
  page.setDefaultTimeout(8000);
  const errors=[];page.on("pageerror",error=>errors.push(error.message));
  await page.addInitScript(() => {
    window.SmartCloudConsentAdmin={restUrl:'https://history.example.test/consent',nonce:'fixture',multisite:true,canManageNetwork:true};
    window.SmartCloudContentRelationsAdmin={restUrl:'https://history.example.test/relations',nonce:'fixture'};
  });
  const config={schemaVersion:1,policyRevision:1,cookieSetHash:'',cookieName:'fixture',cookieDomain:'',cookiePath:'/',maxAgeDays:365,publishedAt:'',categories:[],cookies:[],services:[],catalogs:{}};
  await page.route("**/*", route=> {
    const url=new URL(route.request().url());
    if(url.pathname==='/')return route.fulfill({contentType:"text/html",body:`<!doctype html><html><head><link rel="stylesheet" href="/app.css"></head><body>${isComposer && url.searchParams.has("integrated") ? '<section id="wpsuite-product-details" data-wpsuite-product="ai-editing">' : ''}<div id="${mount}"></div>${isComposer && url.searchParams.has("integrated") ? '</section>' : ''}<script src="/app.js"></script></body></html>`});
    if(url.pathname==='/app.js')return route.fulfill({contentType:"text/javascript",body:bundle.outputFiles.find(f=>f.path.endsWith('.js')).text});
    if(url.pathname==='/app.css')return route.fulfill({contentType:"text/css",body:bundle.outputFiles.find(f=>f.path.endsWith('.css')).text});
    if(url.pathname==='/consent/state')return route.fulfill({json:{scope:url.searchParams.get('scope'),draft:config,published:config,history:[],pendingObservations:[],hasSiteOverride:false}});
    if(url.pathname==='/relations/definitions')return route.fulfill({json:{schemaVersion:1,revision:1,relationTypes:[],selectorPolicy:{postTypes:[],taxonomies:{},selectorKinds:[],searchEnabled:false,maxIds:20,maxPoolSize:100,maxPathLength:10},usageCounts:{},managedRelationTypes:{},availablePostTypes:[],availableTaxonomies:[]}});
    return route.fulfill({status:404,json:{message:`Unexpected fixture route ${url.pathname}`}});
  });
  await page.goto(`https://history.example.test/${search}`);
  return {page,errors};
}
function section(page){return new URL(page.url()).searchParams.get("section");}
async function waitSection(page,value){await page.waitForFunction(expected=>new URL(location.href).searchParams.get("section")===expected,value);}
try {
  await test("Integrated Composer removes legacy intro and preserves product panel during section navigation", async () => {
    const {page, errors} = await fixture("composer", "?page=smartcloud-wpsuite-capability-ai-editing&panel=details&integrated=1&section=configuration");
    try {
      await page.getByRole('button', {name:'Stage fixture edit'}).waitFor();
      assert.equal(await page.locator('h1').count(), 0);
      await page.locator('.mantine-NavLink-root:visible').filter({hasText:/^Content proposals$/}).click();
      await waitSection(page, 'proposals');
      assert.equal(new URL(page.url()).searchParams.get('panel'), 'details');
      await page.evaluate(() => history.back()); await waitSection(page, 'configuration');
      await page.getByRole('button', {name:'Stage fixture edit'}).waitFor();
      assert.deepEqual(errors, []);
    } finally { await page.close(); }
  });
  await test("Composer menu and browser history share staged-edit guard; cancelled Back restores URL and keeps draft",async()=>{
    const {page,errors}=await fixture("composer","?page=smartcloud-composer&keep=1#rail");
    try {
      const nav=name=>page.locator('.mantine-NavLink-root:visible').filter({hasText:new RegExp(`^${name}$`)});
      await nav('Config sets').click();await waitSection(page,'configuration');
      await page.getByRole('button',{name:'Stage fixture edit'}).click();
      let confirmations=0;
      const cancel=async dialog=>{++confirmations;await dialog.dismiss();};
      page.on('dialog',cancel);
      await nav('Content proposals').click();
      assert.equal(section(page),'configuration');assert.equal(confirmations,1);
      await page.evaluate(()=>history.back());
      await page.waitForFunction(()=>new URL(location.href).searchParams.get('section')==='configuration');
      assert.equal(confirmations,2);
      await page.evaluate(()=>history.back());
      await page.waitForFunction(()=>new URL(location.href).searchParams.get('section')==='configuration');
      assert.equal(confirmations,3,'Cancellation must keep staged changes pending');
      page.off('dialog',cancel);page.once('dialog',dialog=>dialog.accept());
      await page.evaluate(()=>history.back());await waitSection(page,'overview');
      await page.locator('.mantine-NavLink-root[data-active]:visible').filter({hasText:'Overview'}).waitFor();
      assert.equal(new URL(page.url()).searchParams.get('keep'),'1');assert.equal(new URL(page.url()).hash,'#rail');
      await page.evaluate(()=>history.forward());await waitSection(page,'configuration');
      await nav('Content proposals').click();await waitSection(page,'proposals');
      await page.reload();await page.getByText('Proposal fixture').waitFor();
      assert.deepEqual(errors,[]);
    }finally{await page.close();}
  });
  for (const [kind,initial,labels] of [
    ['consent','registry',[['services','Services'],['json','Import / export'],['history','Revisions'],['observations','Crawl candidates']]],
    ['relations','relations',[['collections','Collection resources']]],
  ]) await test(`${kind}: all tabs update URLs, preserve query/hash and restore Back/Forward/reload`,async()=>{
    const {page,errors}=await fixture(kind,`?page=${kind}&keep=1&section=${initial}#rail`);
    try {
      for (const [value,label] of labels){
        await page.getByRole('tab',{name:label,exact:value!=='observations'}).click();await waitSection(page,value);
        assert.equal(await page.getByRole('tab',{selected:true}).getAttribute('data-active'),'true');
      }
      await page.evaluate(()=>history.back());await waitSection(page,labels.length>1?labels.at(-2)[0]:initial);
      await page.evaluate(()=>history.forward());await waitSection(page,labels.at(-1)[0]);
      assert.equal(new URL(page.url()).searchParams.get('keep'),'1');assert.equal(new URL(page.url()).hash,'#rail');
      await page.reload();await page.getByRole('tab',{selected:true}).waitFor();
      assert.equal(section(page),labels.at(-1)[0]);assert.deepEqual(errors,[]);
    }finally{await page.close();}
  });
  await test("Consent network guard prevents history from showing site-only observations",async()=>{
    const {page,errors}=await fixture('consent','?page=consent&section=registry');
    try {
      await page.getByRole('tab',{name:'Crawl candidates'}).click();await waitSection(page,'observations');
      await page.getByRole('tab',{name:'Services',exact:true}).click();await waitSection(page,'services');
      await page.getByRole('textbox',{name:'Configuration scope'}).click();
      await page.getByRole('option',{name:'Network defaults'}).click();
      await page.evaluate(()=>history.back());await waitSection(page,'registry');
      assert.equal(await page.getByRole('tab',{selected:true}).textContent(),'Cookie registry');assert.deepEqual(errors,[]);
    }finally{await page.close();}
  });
}finally{await browser.close();}
