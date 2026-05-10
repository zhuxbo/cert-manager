# JRE 安装指南

## 概述

JRE (Java Runtime Environment) 是可选但推荐安装的组件，主要用于以下功能：

- 生成 JKS (Java KeyStore) 格式的证书文件
- 使用 `keytool` 工具进行证书管理和转换
- 支持 Java 相关的证书操作

如果您不需要使用 JKS 格式证书或其他 Java 相关功能，可以跳过此安装。

**注意**: 我们只需要 `keytool` 工具，因此安装 JRE 即可满足需求，无需完整的 JDK。

## 系统要求

- **推荐版本**: JRE 17 或更高版本
- **最低版本**: JRE 11 (但推荐使用最新的 LTS 版本)

## 安装方法

### Linux (Ubuntu/Debian) 安装

```bash
# 更新包列表
sudo apt update

# 安装 OpenJRE 17 (推荐)
sudo apt install openjdk-17-jre

# 验证安装
java -version
keytool --version
```

**其他版本选择**:

```bash
# 查看可用的 JRE 版本
sudo apt list openjdk-*-jre

# 安装其他版本 (如 JRE 21)
sudo apt install openjdk-21-jre
```

### Linux (CentOS/RHEL/Rocky Linux) 安装

```bash
# 更新包列表
sudo yum update
# 或者对于较新的发行版
sudo dnf update

# 安装 OpenJRE 17 (推荐)
sudo yum install java-17-openjdk
# 或者对于较新的发行版
sudo dnf install java-17-openjdk

# 验证安装
java -version
keytool --version
```

### macOS 安装

#### 方法一: 使用 Homebrew (推荐)

```bash
# 安装 Homebrew (如果尚未安装)
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# 安装 OpenJDK 17 (包含JRE)
brew install openjdk@17

# 创建系统链接 (可选，建议执行)
sudo ln -sfn $(brew --prefix)/opt/openjdk@17/libexec/openjdk.jdk /Library/Java/JavaVirtualMachines/openjdk-17.jdk

# 验证安装
java -version
keytool --version
```

#### 方法二: 手动下载安装

1. 访问 [Adoptium](https://adoptium.net/) 下载 macOS 版本的 JRE
2. 选择 "JRE" 而不是 "JDK"
3. 下载 `.pkg` 安装包并运行
4. 按照安装向导完成安装

#### 环境变量配置 (如需要)

如果安装后 `java -version` 仍显示旧版本，需要配置环境变量：

**对于 Zsh (macOS 默认 shell)**:

```bash
# 编辑配置文件
nano ~/.zshrc

# 添加以下内容
export JAVA_HOME=$(/usr/libexec/java_home -v17)
export PATH="$JAVA_HOME/bin:$PATH"

# 重新加载配置
source ~/.zshrc
```

**对于 Bash**:

```bash
# 编辑配置文件
nano ~/.bash_profile

# 添加以下内容
export JAVA_HOME=$(/usr/libexec/java_home -v17)
export PATH="$JAVA_HOME/bin:$PATH"

# 重新加载配置
source ~/.bash_profile
```

### Windows 安装

#### 方法一: 使用 OpenJRE (推荐)

1. 访问 [Adoptium](https://adoptium.net/)
2. 选择以下配置：
    - **Operating System**: Windows
    - **Architecture**: x64
    - **Package Type**: JRE
    - **Version**: 17 (LTS)
3. 下载 `.msi` 安装程序
4. 运行安装程序，按照向导完成安装

#### 方法二: 使用 Oracle JRE

1. 访问 [Oracle Java Downloads](https://www.oracle.com/java/technologies/javase-jre8-downloads.html)
2. 选择 Java SE 17 JRE 下载
3. 下载适用于 Windows 的安装程序
4. 运行安装程序并按照提示安装

#### 环境变量配置

安装完成后需要配置环境变量：

1. **设置 JAVA_HOME**:
    - 右键 "此电脑" → "属性" → "高级系统设置" → "环境变量"
    - 在 "系统变量" 中点击 "新建"
    - 变量名: `JAVA_HOME`
    - 变量值: JRE 安装路径 (例如: `C:\Program Files\Eclipse Adoptium\jre-17.0.x-hotspot`)

2. **更新 PATH 变量**:
    - 在 "系统变量" 中找到 `Path` 变量，点击 "编辑"
    - 点击 "新建"，添加: `%JAVA_HOME%\bin`
    - 点击 "确定" 保存所有设置

#### 验证安装

打开新的命令提示符或 PowerShell 窗口：

```cmd
java -version
keytool --version
```

## 版本管理

### 多版本 JRE 管理

如果需要在系统中安装多个 JRE 版本，可以使用以下工具：

**Linux/macOS**:

- [SDKMAN!](https://sdkman.io/): 强大的 Java 版本管理工具

```bash
# 安装 SDKMAN!
curl -s "https://get.sdkman.io" | bash
source "$HOME/.sdkman/bin/sdkman-init.sh"

# 安装 JRE (通过安装JDK获得JRE功能)
sdk install java 17.0.7-tem

# 切换版本
sdk use java 17.0.7-tem
```

**Windows**:

- [Chocolatey](https://chocolatey.org/): Windows 包管理器

```powershell
# 安装 Chocolatey (以管理员权限运行)
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1'))

# 安装 OpenJDK (包含JRE功能)
choco install openjdk17
```

## 验证和测试

### 基本验证

```bash
# 检查 Java 运行时版本
java -version

# 检查 keytool 工具
keytool --help
```

### SSL 证书管理测试

```bash
# 生成测试 keystore
keytool -genkeypair -alias testkey -keyalg RSA -keysize 2048 -keystore test.jks -validity 365

# 查看 keystore 内容
keytool -list -v -keystore test.jks

# 删除测试文件
rm test.jks
```

## 常见问题

### 1. 命令找不到

**问题**: `java: command not found` 或 `'java' 不是内部或外部命令`

**解决方案**:

- 确认 JRE 已正确安装
- 检查环境变量 `JAVA_HOME` 和 `PATH` 配置
- 重新启动终端或命令提示符

### 2. 版本冲突

**问题**: 系统显示错误的 Java 版本

**解决方案**:

- 使用 `which java` (Linux/macOS) 或 `where java` (Windows) 检查当前使用的 Java 路径
- 调整 `PATH` 环境变量顺序，确保新版本路径在前
- 考虑卸载旧版本 Java

### 3. keytool 不可用

**问题**: `keytool: command not found`

**解决方案**:

- 确认安装的是包含 keytool 的 JRE 版本
- 检查 `keytool` 是否在 `$JAVA_HOME/bin` 目录中
- 确认 `$JAVA_HOME/bin` 已添加到 `PATH` 环境变量
- 注意：某些精简版 JRE 可能不包含 keytool，建议安装完整版

### 4. 权限问题 (Linux/macOS)

**问题**: 无法执行 Java 相关命令

**解决方案**:

```bash
# 检查文件权限
ls -la $JAVA_HOME/bin/java

# 如果需要，修复权限
sudo chmod +x $JAVA_HOME/bin/*
```

## 卸载 JRE

### Linux 卸载

```bash
# Ubuntu/Debian
sudo apt remove openjdk-17-jre
sudo apt autoremove

# CentOS/RHEL
sudo yum remove java-17-openjdk
```

### macOS 卸载

```bash
# 如果使用 Homebrew 安装
brew uninstall openjdk@17

# 手动安装的需要删除目录
sudo rm -rf /Library/Java/JavaVirtualMachines/jre-17.x.x.jre
# 或者JDK目录（如果安装的是JDK）
sudo rm -rf /Library/Java/JavaVirtualMachines/jdk-17.x.x.jdk
```

### Windows 卸载

- 通过 "控制面板" → "程序和功能" 卸载
- 或使用 Chocolatey: `choco uninstall openjdk17`

## 相关资源

- [OpenJDK 官网](https://openjdk.org/)
- [Adoptium (Eclipse Temurin)](https://adoptium.net/)
- [Oracle Java](https://www.oracle.com/java/technologies/javase-downloads.html)
- [SDKMAN! 版本管理](https://sdkman.io/)
- [Java keytool 文档](https://docs.oracle.com/en/java/javase/17/docs/specs/man/keytool.html)
