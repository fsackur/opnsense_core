#! /usr/bin/env python3

import os
import subprocess
from typing import *

base_path = os.path.normpath(f"{os.path.dirname(__file__)}/../plugins")

plugin_dirs = []
for file_name in os.listdir(base_path):
    if file_name.startswith(".") or file_name[0].isupper(): continue
    if file_name == "devel": continue

    topic_path = f"{base_path}/{file_name}"
    if not os.path.isdir(topic_path): continue

    _plugin_dirs = [f"{topic_path}/{plugin}" for plugin in os.listdir(topic_path)]
    plugin_dirs.extend(_plugin_dirs)


for plugin_dir in plugin_dirs:
    subprocess.run(["make", "install"], cwd=plugin_dir)
