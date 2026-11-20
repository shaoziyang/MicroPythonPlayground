#!/usr/bin/env python3
"""从 micropython.mjs + micropython.wasm 生成外置内核包 micropython.bundle.js。

用途：以 file:// 方式打开 HTML 时，浏览器会拦截对本地 .mjs / .wasm 的
动态 import 与 fetch。本脚本把内核源码（去掉 ESM 语法）与 wasm（base64）
打包进一个普通 JS 文件，页面用经典 <script src> 加载它即可绕过该限制。

使用：下载更新得到新的 micropython.mjs / micropython.wasm 后，把它们放到
本脚本同一目录，运行：

    python3 make_bundle.py

然后重新打开 HTML 即可用上新内核。http(s) 方式打开时页面不用这个包，
始终读取外部 micropython.mjs / micropython.wasm，因此不受影响。
"""
import base64
from pathlib import Path

HERE = Path(__file__).resolve().parent
mjs_path = HERE / 'micropython.mjs'
wasm_path = HERE / 'micropython.wasm'
out_path = HERE / 'micropython.bundle.js'

# 浏览器环境不会进入 Node 分支，这里的占位 URL 仅用于替换 import.meta.url
PLACEHOLDER = '"http://localhost/micropython.mjs"'


def main() -> None:
    if not mjs_path.is_file() or not wasm_path.is_file():
        raise SystemExit('未找到 micropython.mjs / micropython.wasm，请把它们放到本脚本同目录。')

    mjs = mjs_path.read_text(encoding='utf-8')
    wasm = wasm_path.read_bytes()

    mod = mjs.replace('import.meta.url', PLACEHOLDER)
    if mod.count('export default _createMicroPythonModule;') != 1:
        raise SystemExit('micropython.mjs 格式与预期不符（export default 锚点异常）。')
    mod = mod.replace('export default _createMicroPythonModule;',
                      'var __mpDefault = _createMicroPythonModule;')
    if mod.count('export async function loadMicroPython(') != 1:
        raise SystemExit('micropython.mjs 格式与预期不符（loadMicroPython 锚点异常）。')
    mod = mod.replace('export async function loadMicroPython(',
                      'async function loadMicroPython(')

    wasm_b64 = base64.b64encode(wasm).decode('ascii')

    # 顶层有 `await import("node:module")`（Node 分支），必须包在 async 函数里。
    # 用 globalThis 挂载，使主线程与经典 Worker（importScripts）都能取到。
    bundle = (
        '(async function(){\n'
        + mod + '\n'
        + 'globalThis.__MP_BUNDLE__ = { default: __mpDefault, '
        + 'loadMicroPython: loadMicroPython, wasmBase64: ' + repr(wasm_b64) + ' };\n'
        + '})().catch(function(e){ globalThis.__MP_BUNDLE_ERR__ = String(e); });\n'
    )
    out_path.write_text(bundle, encoding='utf-8')
    print(f'已生成 {out_path.name}（{len(bundle)} 字节；内核 wasm {len(wasm)} 字节）')


if __name__ == '__main__':
    main()
