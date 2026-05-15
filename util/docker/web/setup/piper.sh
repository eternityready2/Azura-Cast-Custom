#!/bin/bash
set -e
set -x

# Runtime deps for Piper TTS (OpenMP for ONNX Runtime)
apt-get install -y --no-install-recommends libgomp1

# Per-architecture Piper install
ARCHITECTURE=x86_64
if [[ "$(uname -m)" = "aarch64" ]]; then
    ARCHITECTURE=aarch64
fi

PIPER_VERSION="2023.11.14-2"

wget -O /tmp/piper.tar.gz \
  "https://github.com/rhasspy/piper/releases/download/${PIPER_VERSION}/piper_linux_${ARCHITECTURE}.tar.gz"

mkdir -p /usr/local/share/piper
tar -xzf /tmp/piper.tar.gz -C /usr/local/share/piper/ --strip-components=1
ln -sf /usr/local/share/piper/piper /usr/local/bin/piper
chmod a+x /usr/local/share/piper/piper

install_piper_voice() {
  local voice_dir="$1"
  local voice_file="$2"

  mkdir -p "/usr/local/share/piper-voices/${voice_dir}"
  wget -O "/usr/local/share/piper-voices/${voice_dir}/${voice_file}.onnx" \
    "https://huggingface.co/rhasspy/piper-voices/resolve/main/${voice_dir}/${voice_file}.onnx"
  wget -O "/usr/local/share/piper-voices/${voice_dir}/${voice_file}.onnx.json" \
    "https://huggingface.co/rhasspy/piper-voices/resolve/main/${voice_dir}/${voice_file}.onnx.json"
}

install_piper_voice "en/en_US/lessac/medium" "en_US-lessac-medium"
install_piper_voice "en/en_US/joe/medium" "en_US-joe-medium"
install_piper_voice "en/en_US/ryan/medium" "en_US-ryan-medium"

rm -f /tmp/piper.tar.gz
