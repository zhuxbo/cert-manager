import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { parse } from "vue/compiler-sfc";

const frontendRoot = new URL("../../../../../../../", import.meta.url);

function read(channel, file) {
  return readFileSync(
    new URL(
      `${channel}/src/views/order/detail/components/smime/${file}`,
      frontendRoot
    ),
    "utf8"
  );
}

function readProcess(channel, productType) {
  return readFileSync(
    new URL(
      `${channel}/src/views/order/detail/components/${productType}/process.vue`,
      frontendRoot
    ),
    "utf8"
  );
}

function assertElementAlwaysVisible(
  source,
  marker,
  tagName,
  expectedOpeningTag
) {
  const template = parse(source).descriptor.template;
  assert.ok(template, "未找到 Vue template");

  const matches = [];

  function visit(node, ancestors) {
    if (node.type !== 0 && node.type !== 1) {
      return;
    }

    const elementAncestors = node.type === 1 ? [...ancestors, node] : ancestors;

    if (
      node.type === 1 &&
      node.tag === tagName &&
      node.loc.source.includes(marker)
    ) {
      matches.push(elementAncestors);
    }

    for (const child of node.children) {
      visit(child, elementAncestors);
    }
  }

  visit(template.ast, []);
  assert.equal(matches.length, 1, `应唯一定位 ${marker} 的 <${tagName}>`);

  const path = matches[0];
  const target = path.at(-1);
  const openingTag = target.loc.source.slice(
    0,
    target.loc.source.indexOf(">") + 1
  );
  assert.equal(openingTag, expectedOpeningTag);

  const conditionalDirectives = new Set(["if", "else-if", "else", "show"]);
  for (const element of path) {
    const conditional = element.props.find(
      prop => prop.type === 7 && conditionalDirectives.has(prop.name)
    );
    assert.equal(
      conditional,
      undefined,
      `${marker} 不应被 <${element.tag}> 的 v-${conditional?.name} 隐藏`
    );
  }
}

test("S/MIME 已签发时两端只隐藏验证操作行并保留邮箱信息", () => {
  for (const channel of ["admin", "user"]) {
    const validation = read(channel, "validation.vue");
    assert.match(
      validation,
      /v-if="\[[^\]]*'processing'[^\]]*\]\.includes\(cert\.status\)"/
    );
    assertElementAlwaysVisible(
      validation,
      "order.contact?.email || cert.common_name",
      "div",
      '<div class="descriptions">'
    );

    const source = read(channel, "process.vue");
    assertElementAlwaysVisible(source, "邮箱验证", "tr", "<tr>");
    assertElementAlwaysVisible(source, "<SmimeValidation />", "tr", "<tr>");
    assert.match(
      source,
      /<tr v-if="cert\.status === 'active'">[\s\S]*?<SmimeInstall \/>[\s\S]*?<\/tr>/
    );
    assert.match(source, /<td class="content">下载证书<\/td>/);
    assert.doesNotMatch(source, /签发证书/);
  }
});

test("S/MIME 下载组件两端都使用固定 PEM/PFX 选项", () => {
  for (const channel of ["admin", "user"]) {
    const source = read(channel, "install.vue");
    assert.match(source, /getSmimeDownloadOptions/);
    assert.doesNotMatch(source, /private_key/);
    assert.match(source, /OrderApi\.download\(order\.id, option\.value\)/);
  }
});

test("可下载证书与人工签发证书的步骤标题保持产品语义", () => {
  for (const channel of ["admin", "user"]) {
    assert.match(readProcess(channel, "ssl"), /下载证书/);
    assert.match(readProcess(channel, "smime"), /下载证书/);
    assert.doesNotMatch(readProcess(channel, "smime"), /签发证书/);
    assert.match(readProcess(channel, "codesign"), /签发证书/);
    assert.match(readProcess(channel, "docsign"), /签发证书/);
  }
});
